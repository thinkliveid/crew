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
use Thinkliveid\Crew\Contracts\SupportsSubAgents;
use Thinkliveid\Crew\Install\AgentsDetector;
use Thinkliveid\Crew\Install\Agents\Agent;
use Thinkliveid\Crew\Install\GuidelineWriter;
use Thinkliveid\Crew\Skills\Remote\GitHubRepository;
use Thinkliveid\Crew\SubAgents\Remote\GitHubSubAgentProvider;
use Thinkliveid\Crew\Support\Config;

#[AsCommand(
  name: 'add:subagent',
  description: 'Add sub-agents from a GitHub repository'
)]
class AddSubAgentCommand extends Command
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
      $this->displayBoostHeader('Add Sub-agent', basename(getcwd()));
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

    $this->installGitHubSubAgents($repos, $interactive, $io, $input, $output);

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
      $repo = $io->ask('Enter a GitHub repository (owner/repo) to install sub-agents from (leave empty to skip)', '');

      if ($repo !== '' && $repo !== null)
      {
        return [$repo];
      }

      return [];
    }

    return $this->config->getSubAgents();
  }

  /**
   * @param array<int, string> $repos
   */
  protected function installGitHubSubAgents(array $repos, bool $interactive, SymfonyStyle $io, InputInterface $input, OutputInterface $output): void
  {
    $io->section('GitHub Sub-agents');
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
      $io->text("Discovering sub-agents in <info>{$repository->fullName()}</info>...");
      $provider = new GitHubSubAgentProvider($repository, $this->config);

      try
      {
        $subAgents = $provider->discoverSubAgents();
      }
      catch (\RuntimeException $e)
      {
        $io->error($e->getMessage());
        continue;
      }

      if (empty($subAgents))
      {
        $io->warning("No sub-agents found in {$repository->fullName()}.");
        continue;
      }

      if ($interactive)
      {
        $choices = [];
        foreach ($subAgents as $agent)
        {
          $choices[$agent->name] = $agent->name;
        }

        $question = new ChoiceQuestion(
          "Select sub-agents to install from {$repository->fullName()} (comma-separated numbers)",
          array_values($choices),
          implode(',', array_keys(array_values($choices))),
        );

        $question->setMultiselect(true);
        $helper = $this->getHelper('question');
        $selected = $helper->ask($input, $output, $question);
        $agentsToInstall = array_filter($subAgents, fn($agent) => in_array($agent->name, $selected, true));
      }
      else
      {
        $agentsToInstall = $subAgents;
      }

      $targetPaths = $this->getSubAgentPaths();
      foreach ($agentsToInstall as $agent)
      {
        foreach ($targetPaths as $agentDisplayName => $basePath)
        {
          $io->text("  Installing <info>{$agent->name}</info> → {$agentDisplayName}...");

          $success = $provider->downloadSubAgent($agent, $basePath);
          if ($success)
          {
            $io->text("  <info>✓</info> {$agent->name} → {$agentDisplayName}");
          }
          else
          {
            $io->text("  <error>✗</error> Failed to download {$agent->name} for {$agentDisplayName}");
          }
        }
      }
    }

    // Persist repos to crew.json
    $existingRepos = $this->config->getSubAgents();
    $mergedRepos = array_values(array_unique(array_merge($existingRepos, $allRepos)));
    $this->config->setSubAgents($mergedRepos);
  }

  /**
   * Get sub-agent target paths for configured agents.
   *
   * @return array<string, string> displayName => absolute path
   */
  protected function getSubAgentPaths(): array
  {
    $agentNames = $this->config->getAgents();
    $basePath = getcwd();

    if (empty($agentNames))
    {
      return ['default' => $basePath . '/.claude/agents'];
    }

    $paths = [];
    foreach ($this->agentsDetector->getAgents() as $agent)
    {
      if (in_array($agent->name(), $agentNames, true) && $agent instanceof SupportsSubAgents)
      {
        $paths[$agent->displayName()] = $basePath . '/' . $agent->subAgentsPath();
      }
    }

    return !empty($paths) ? $paths : ['default' => $basePath . '/.claude/agents'];
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
