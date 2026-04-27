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
use Thinkliveid\Crew\Commands\Remote\GitHubCommandProvider;
use Thinkliveid\Crew\Concerns\DisplayHelper;
use Thinkliveid\Crew\Contracts\SupportsCommands;
use Thinkliveid\Crew\Contracts\SupportsGuidelines;
use Thinkliveid\Crew\Install\AgentsDetector;
use Thinkliveid\Crew\Install\Agents\Agent;
use Thinkliveid\Crew\Install\GuidelineWriter;
use Thinkliveid\Crew\Skills\Remote\GitHubRepository;
use Thinkliveid\Crew\Support\Config;

#[AsCommand(
  name: 'add:command',
  description: 'Add slash commands from a GitHub repository'
)]
class AddCommandCommand extends Command
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
      $this->displayBoostHeader('Add Command', basename(getcwd()));
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

    $this->installGitHubCommands($repos, $interactive, $io, $input, $output);

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
      $repo = $io->ask('Enter a GitHub repository (owner/repo) to install commands from (leave empty to skip)', '');

      if ($repo !== '' && $repo !== null)
      {
        return [$repo];
      }

      return [];
    }

    return $this->config->getCommands();
  }

  /**
   * @param array<int, string> $repos
   */
  protected function installGitHubCommands(array $repos, bool $interactive, SymfonyStyle $io, InputInterface $input, OutputInterface $output): void
  {
    $io->section('GitHub Commands');
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
      $io->text("Discovering commands in <info>{$repository->fullName()}</info>...");
      $provider = new GitHubCommandProvider($repository, $this->config);

      try
      {
        $commands = $provider->discoverCommands();
      }
      catch (\RuntimeException $e)
      {
        $io->error($e->getMessage());
        continue;
      }

      if (empty($commands))
      {
        $io->warning("No commands found in {$repository->fullName()}.");
        continue;
      }

      if ($interactive)
      {
        $choices = [];
        foreach ($commands as $command)
        {
          $choices[$command->name] = $command->name;
        }

        $question = new ChoiceQuestion(
          "Select commands to install from {$repository->fullName()} (comma-separated numbers)",
          array_values($choices),
          implode(',', array_keys(array_values($choices))),
        );

        $question->setMultiselect(true);
        $helper = $this->getHelper('question');
        $selected = $helper->ask($input, $output, $question);
        $commandsToInstall = array_filter($commands, fn($command) => in_array($command->name, $selected, true));
      }
      else
      {
        $commandsToInstall = $commands;
      }

      $targetPaths = $this->getCommandPaths();
      foreach ($commandsToInstall as $command)
      {
        foreach ($targetPaths as $agentDisplayName => $basePath)
        {
          $io->text("  Installing <info>{$command->name}</info> → {$agentDisplayName}...");

          $success = $provider->downloadCommand($command, $basePath);
          if ($success)
          {
            $io->text("  <info>✓</info> {$command->name} → {$agentDisplayName}");
          }
          else
          {
            $io->text("  <error>✗</error> Failed to download {$command->name} for {$agentDisplayName}");
          }
        }
      }
    }

    $existingRepos = $this->config->getCommands();
    $mergedRepos = array_values(array_unique(array_merge($existingRepos, $allRepos)));
    $this->config->setCommands($mergedRepos);
  }

  /**
   * @return array<string, string>
   */
  protected function getCommandPaths(): array
  {
    $agentNames = $this->config->getAgents();
    $basePath = getcwd();

    if (empty($agentNames))
    {
      return ['default' => $basePath . '/.claude/commands'];
    }

    $paths = [];
    foreach ($this->agentsDetector->getAgents() as $agent)
    {
      if (in_array($agent->name(), $agentNames, true) && $agent instanceof SupportsCommands)
      {
        $paths[$agent->displayName()] = $basePath . '/' . $agent->commandsPath();
      }
    }

    return !empty($paths) ? $paths : ['default' => $basePath . '/.claude/commands'];
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
      $commands = $writer->collectCommandInfo($agent);

      if (empty($skills) && empty($subAgents) && empty($commands))
      {
        continue;
      }

      if ($writer->writeAll($agent, $skills, $subAgents, $commands))
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
