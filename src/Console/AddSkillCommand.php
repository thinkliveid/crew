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
use Thinkliveid\Crew\Contracts\SupportsSkills;
use Thinkliveid\Crew\Install\AgentsDetector;
use Thinkliveid\Crew\Install\Agents\Agent;
use Thinkliveid\Crew\Install\GuidelineWriter;
use Thinkliveid\Crew\Skills\Remote\GitHubRepository;
use Thinkliveid\Crew\Skills\Remote\GitHubSkillProvider;
use Thinkliveid\Crew\Support\Config;

#[AsCommand(
  name: 'add:skill',
  description: 'Add skills from a GitHub repository'
)]
class AddSkillCommand extends Command
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
      $this->displayBoostHeader('Add Skill', basename(getcwd()));
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

    $this->installGitHubSkills($repos, $interactive, $io, $input, $output);

    // --- Write skill activation to guideline files ---
    $this->writeSkillGuidelines($interactive, $io);

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
      $repo = $io->ask('Enter a GitHub repository (owner/repo) to install skills from (leave empty to skip)', '');

      if ($repo !== '' && $repo !== null)
      {
        return [$repo];
      }

      return [];
    }

    return $this->config->getSkills();
  }

  /**
   * @param array<int, string> $repos
   */
  protected function installGitHubSkills(array $repos, bool $interactive, SymfonyStyle $io, InputInterface $input, OutputInterface $output): void
  {
    $io->section('GitHub Skills');
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
      $io->text("Discovering skills in <info>{$repository->fullName()}</info>...");
      $provider = new GitHubSkillProvider($repository, $this->config);

      try
      {
        $skills = $provider->discoverSkills();
      }
      catch (\RuntimeException $e)
      {
        $io->error($e->getMessage());
        continue;
      }

      if (empty($skills))
      {
        $io->warning("No skills found in {$repository->fullName()}.");
        continue;
      }

      if ($interactive)
      {
        $choices = [];
        foreach ($skills as $skill)
        {
          $choices[$skill->name] = $skill->name;
        }

        $question = new ChoiceQuestion(
          "Select skills to install from {$repository->fullName()} (comma-separated numbers)",
          array_values($choices),
          implode(',', array_keys(array_values($choices))),
        );

        $question->setMultiselect(true);
        $helper = $this->getHelper('question');
        $selected = $helper->ask($input, $output, $question);
        $skillsToInstall = array_filter($skills, fn($skill) => in_array($skill->name, $selected, true));
      }
      else
      {
        $skillsToInstall = $skills;
      }

      $skillPaths = $this->getSkillPaths();
      foreach ($skillsToInstall as $skill)
      {
        foreach ($skillPaths as $agentDisplayName => $basePath)
        {
          $targetPath = $basePath . '/' . $skill->name;
          $io->text("  Installing <info>{$skill->name}</info> → {$agentDisplayName}...");

          $success = $provider->downloadSkill($skill, $targetPath);
          if ($success)
          {
            $io->text("  <info>✓</info> {$skill->name} → {$agentDisplayName}");
          }
          else
          {
            $io->text("  <error>✗</error> Failed to download {$skill->name} for {$agentDisplayName}");
          }
        }
      }
    }

    // Persist repos to crew.json
    $existingRepos = $this->config->getSkills();
    $mergedRepos = array_values(array_unique(array_merge($existingRepos, $allRepos)));
    $this->config->setSkills($mergedRepos);
  }

  /**
   * Get skill target paths for configured agents.
   * Falls back to .claude/skills if no agents are configured.
   *
   * @return array<string, string> displayName => absolute path
   */
  protected function getSkillPaths(): array
  {
    $agentNames = $this->config->getAgents();
    $basePath = getcwd();

    if (empty($agentNames))
    {
      return ['default' => $basePath . '/.claude/skills'];
    }

    $paths = [];
    foreach ($this->agentsDetector->getAgents() as $agent)
    {
      if (in_array($agent->name(), $agentNames, true) && $agent instanceof SupportsSkills)
      {
        $paths[$agent->displayName()] = $basePath . '/' . $agent->skillsPath();
      }
    }

    return !empty($paths) ? $paths : ['default' => $basePath . '/.claude/skills'];
  }

  /**
   * Write skill activation to guideline files for configured agents.
   */
  protected function writeSkillGuidelines(bool $interactive, SymfonyStyle $io): void
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
      if (empty($skills))
      {
        continue;
      }

      if ($writer->write($agent, $skills))
      {
        $written[] = $agent->guidelinesPath();
      }
    }

    if (!empty($written) && $interactive)
    {
      $io->section('Skill Guidelines');
      $io->text('Updated guideline files with skill activation:');
      $io->listing($written);
    }
  }
}
