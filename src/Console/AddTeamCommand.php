<?php

declare(strict_types=1);

namespace Thinkliveid\Crew\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;
use Thinkliveid\Crew\Concerns\DisplayHelper;
use Thinkliveid\Crew\Contracts\SupportsGuidelines;
use Thinkliveid\Crew\Contracts\SupportsTeams;
use Thinkliveid\Crew\Install\AgentsDetector;
use Thinkliveid\Crew\Install\Agents\Agent;
use Thinkliveid\Crew\Install\GuidelineWriter;
use Thinkliveid\Crew\Skills\Remote\GitHubRepository;
use Thinkliveid\Crew\Teams\Remote\GitHubTeamProvider;
use Thinkliveid\Crew\Support\Config;

#[AsCommand(
  name: 'add:team',
  description: 'Add team templates from a GitHub repository'
)]
class AddTeamCommand extends Command
{
  use DisplayHelper;

  public function __construct(protected Config $config, protected AgentsDetector $agentsDetector)
  {
    parent::__construct();
  }

  protected function configure(): void
  {
    $this->addArgument('repository', InputArgument::OPTIONAL, 'GitHub repository (owner/repo or full URL)');
  }

  protected function execute(InputInterface $input, OutputInterface $output): int
  {
    $this->output = $output;
    $io = new SymfonyStyle($input, $output);
    $interactive = $input->isInteractive();

    if ($interactive)
    {
      $this->displayBoostHeader('Add Team', basename(getcwd()));
    }

    $repos = $this->resolveRepositories($input, $io);
    if (empty($repos))
    {
      if ($interactive)
      {
        $io->info('No GitHub repository specified. Nothing to install.');
      }

      return Command::SUCCESS;
    }

    $this->installGitHubTeams($repos, $interactive, $io, $input, $output);

    // --- Write guidelines ---
    $this->writeGuidelines($interactive, $io);

    return Command::SUCCESS;
  }

  /**
   * @return array<int, string>
   */
  protected function resolveRepositories(InputInterface $input, SymfonyStyle $io): array
  {
    $argument = $input->getArgument('repository');
    if ($argument !== null)
    {
      return [$argument];
    }

    if ($input->isInteractive())
    {
      $repo = $io->ask('Enter a GitHub repository (owner/repo) to install teams from (leave empty to skip)', '');

      if ($repo !== '' && $repo !== null)
      {
        return [$repo];
      }

      return [];
    }

    return $this->config->getTeams();
  }

  /**
   * @param array<int, string> $repos
   */
  protected function installGitHubTeams(array $repos, bool $interactive, SymfonyStyle $io, InputInterface $input, OutputInterface $output): void
  {
    $io->section('GitHub Teams');
    $allRepos = [];

    foreach ($repos as $repoInput)
    {
      try
      {
        $repository = GitHubRepository::fromInput($repoInput);
      }
      catch (\InvalidArgumentException $e)
      {
        $io->error("Invalid repository: {$repoInput} — {$e->getMessage()}");
        continue;
      }

      $allRepos[] = $repository->fullName();
      $io->text("Discovering teams in <info>{$repository->fullName()}</info>...");
      $provider = new GitHubTeamProvider($repository, $this->config);

      try
      {
        $teams = $provider->discoverTeams();
      }
      catch (\RuntimeException $e)
      {
        $io->error($e->getMessage());
        continue;
      }

      if (empty($teams))
      {
        $io->warning("No teams found in {$repository->fullName()}.");
        continue;
      }

      if ($interactive)
      {
        $choices = [];
        foreach ($teams as $team)
        {
          $choices[$team->name] = $team->name;
        }

        $question = new ChoiceQuestion(
          "Select teams to install from {$repository->fullName()} (comma-separated numbers)",
          array_values($choices),
          implode(',', array_keys(array_values($choices))),
        );

        $question->setMultiselect(true);
        $helper = $this->getHelper('question');
        $selected = $helper->ask($input, $output, $question);
        $teamsToInstall = array_filter($teams, fn($team) => in_array($team->name, $selected, true));
      }
      else
      {
        $teamsToInstall = $teams;
      }

      $targetPaths = $this->getTeamPaths();
      foreach ($teamsToInstall as $team)
      {
        foreach ($targetPaths as $agentDisplayName => $basePath)
        {
          $targetPath = $basePath . '/' . $team->name;
          $io->text("  Installing <info>{$team->name}</info> → {$agentDisplayName}...");

          $success = $provider->downloadTeam($team, $targetPath);
          if ($success)
          {
            $io->text("  <info>✓</info> {$team->name} → {$agentDisplayName}");
          }
          else
          {
            $io->text("  <error>✗</error> Failed to download {$team->name} for {$agentDisplayName}");
          }
        }
      }
    }

    // Persist repos to crew.json
    $existingRepos = $this->config->getTeams();
    $mergedRepos = array_values(array_unique(array_merge($existingRepos, $allRepos)));
    $this->config->setTeams($mergedRepos);
  }

  /**
   * Get team target paths for configured agents.
   *
   * @return array<string, string> displayName => absolute path
   */
  protected function getTeamPaths(): array
  {
    $agentNames = $this->config->getAgents();
    $basePath = getcwd();

    if (empty($agentNames))
    {
      return ['default' => $basePath . '/.claude/teams'];
    }

    $paths = [];
    foreach ($this->agentsDetector->getAgents() as $agent)
    {
      if (in_array($agent->name(), $agentNames, true) && $agent instanceof SupportsTeams)
      {
        $paths[$agent->displayName()] = $basePath . '/' . $agent->teamsPath();
      }
    }

    return !empty($paths) ? $paths : ['default' => $basePath . '/.claude/teams'];
  }

  protected function writeGuidelines(bool $interactive, SymfonyStyle $io): void
  {
    $agentNames = $this->config->getAgents();
    if (empty($agentNames))
    {
      return;
    }

    $basePath = getcwd();
    $writer = new GuidelineWriter($basePath);
    $written = [];

    foreach ($this->agentsDetector->getAgents() as $agent)
    {
      if (!in_array($agent->name(), $agentNames, true))
      {
        continue;
      }

      if (!$agent instanceof SupportsGuidelines)
      {
        continue;
      }

      /** @var Agent&SupportsGuidelines $agent */
      $skills = $writer->collectSkillInfo($agent);
      $subAgents = $writer->collectSubAgentInfo($agent);
      $teams = $writer->collectTeamInfo($agent);

      if (empty($skills) && empty($subAgents) && empty($teams))
      {
        continue;
      }

      if ($writer->writeAll($agent, $skills, $subAgents, $teams))
      {
        $written[] = $agent->guidelinesPath();
      }
    }

    if (!empty($written) && $interactive)
    {
      $io->section('Guidelines');
      $io->text('Updated guideline files:');
      $io->listing($written);
    }
  }
}
