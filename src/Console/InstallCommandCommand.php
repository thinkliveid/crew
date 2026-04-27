<?php

declare(strict_types=1);

namespace Thinkliveid\Crew\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Thinkliveid\Crew\Commands\BuiltIn\BuiltInCommandProvider;
use Thinkliveid\Crew\Commands\Local\LocalCommandProvider;
use Thinkliveid\Crew\Concerns\DisplayHelper;
use Thinkliveid\Crew\Contracts\SupportsCommands;
use Thinkliveid\Crew\Contracts\SupportsGuidelines;
use Thinkliveid\Crew\Install\AgentsDetector;
use Thinkliveid\Crew\Install\Agents\Agent;
use Thinkliveid\Crew\Install\GuidelineWriter;
use Thinkliveid\Crew\Support\Config;

#[AsCommand(
  name: 'install:command',
  description: 'Detect agents, sync local slash commands, and store configuration'
)]
class InstallCommandCommand extends Command
{
  use DisplayHelper;

  /** @var array<string> */
  private array $systemInstalledAgents = [];

  /** @var array<string> */
  private array $projectInstalledAgents = [];

  public function __construct(private readonly Config $config, private readonly AgentsDetector $agentsDetector)
  {
    parent::__construct();
  }

  protected function configure(): void
  {
    $this->addOption('skip-detection', null, InputOption::VALUE_NONE, 'Skip agent detection and use agents from crew.json');
  }

  protected function execute(InputInterface $input, OutputInterface $output): int
  {
    $this->output = $output;
    $io = new SymfonyStyle($input, $output);
    $interactive = $input->isInteractive();
    $skipDetection = (bool)$input->getOption('skip-detection');

    if ($interactive)
    {
      $this->displayBoostHeader('Commands', basename(getcwd()));
    }

    if ($skipDetection)
    {
      $selectedAgentNames = $this->config->getAgents();
    }
    else
    {
      $this->discoverEnvironment();
      $selectedAgentNames = $this->selectAgents($input, $io, $interactive);

      if (!empty($selectedAgentNames))
      {
        $this->config->setAgents($selectedAgentNames);

        if ($interactive)
        {
          $io->success(sprintf('Saved %d agent(s): %s', count($selectedAgentNames), implode(', ', $selectedAgentNames)));
        }
      }
    }

    $selectedAgents = $this->resolveAgentInstances($selectedAgentNames);
    $this->publishBuiltInCommands($interactive, $io);
    $this->syncLocalCommandsForAgents($selectedAgents, $interactive, $io);
    $this->installConfiguredCommands($interactive, $io, $output);
    $this->writeGuidelines($selectedAgents, $interactive, $io);

    return Command::SUCCESS;
  }

  private function discoverEnvironment(): void
  {
    $this->systemInstalledAgents = $this->agentsDetector->discoverSystemInstalledAgents();
    $this->projectInstalledAgents = $this->agentsDetector->discoverProjectInstalledAgents(getcwd());
  }

  /**
   * @return array<string>
   */
  private function selectAgents(InputInterface $input, SymfonyStyle $io, bool $interactive): array
  {
    $allAgents = $this->agentsDetector->getAgents();
    if (empty($allAgents))
    {
      return [];
    }

    $options = [];
    foreach ($allAgents as $agent)
    {
      $options[$agent->name()] = $agent->displayName();
    }

    asort($options);

    $savedAgents = $this->config->getAgents();
    $validSaved = array_filter($savedAgents, fn(string $name): bool => isset($options[$name]));

    if (!empty($validSaved))
    {
      $defaults = array_values($validSaved);
    }
    else
    {
      $detected = array_unique(array_merge($this->systemInstalledAgents, $this->projectInstalledAgents));
      $defaults = array_values(array_filter($detected, fn(string $name): bool => isset($options[$name])));
    }

    if (!$interactive)
    {
      return $defaults;
    }

    $io->section('Agent Detection');

    $detected = array_unique(array_merge($this->systemInstalledAgents, $this->projectInstalledAgents));
    $relevant = array_unique(array_merge($detected, $savedAgents));
    if (empty($relevant))
    {
      $io->warning('No agents detected on your system or project.');
      return [];
    }

    $selected = [];
    foreach ($options as $name => $displayName)
    {
      if (!in_array($name, $relevant, true))
      {
        continue;
      }

      $isDefault = in_array($name, $defaults, true);
      $isDetected = in_array($name, $detected, true);

      $label = $isDetected
        ? sprintf('Configure <info>%s</info> (detected)?', $displayName)
        : sprintf('Configure <info>%s</info>?', $displayName);

      if ($io->confirm($label, $isDefault))
      {
        $selected[] = $name;
      }
    }

    return $selected;
  }

  /**
   * @param array<string> $names
   * @return array<Agent>
   */
  private function resolveAgentInstances(array $names): array
  {
    $allAgents = $this->agentsDetector->getAgents();
    return array_values(array_filter($allAgents,
      static fn(Agent $agent): bool => in_array($agent->name(), $names, true)
    ));
  }

  /**
   * @param array<Agent> $agents
   */
  private function syncLocalCommandsForAgents(array $agents, bool $interactive, SymfonyStyle $io): void
  {
    $basePath = getcwd();

    $sourceProvider = new LocalCommandProvider($basePath);
    $localCommands = $sourceProvider->discoverCommands();

    $invalidCommands = $sourceProvider->getInvalidCommands();

    if (!empty($invalidCommands) && $interactive)
    {
      $io->warning('Some local commands failed validation and will be skipped:');
      foreach ($invalidCommands as $name => $result)
      {
        $io->text(sprintf('  <comment>%s</comment>:', $name));
        foreach ($result->errors as $error)
        {
          $io->text(sprintf('    - %s', $error));
        }
      }

      $io->newLine();
    }

    if (empty($localCommands))
    {
      if ($interactive)
      {
        $io->info('No valid local commands found in .ai/commands/.');
      }
      return;
    }

    $commandAgents = array_filter($agents, fn(Agent $agent): bool => $agent instanceof SupportsCommands);
    if (empty($commandAgents))
    {
      if ($interactive)
      {
        $io->warning('No selected agents support slash commands.');
      }
      return;
    }

    if ($interactive)
    {
      $io->section('Local Commands');
      $io->listing($localCommands);

      $agentNames = array_map(fn(Agent $a): string => $a->displayName(), $commandAgents);
      $proceed = $io->confirm(
        sprintf('Sync these local commands to %s?', implode(', ', $agentNames)),
        true
      );

      if (!$proceed)
      {
        $io->comment('Skipped local command sync.');
        return;
      }
    }

    foreach ($commandAgents as $agent)
    {
      /** @var Agent&SupportsCommands $agent */
      $targetPath = $basePath . '/' . $agent->commandsPath();
      $provider = new LocalCommandProvider($basePath, $targetPath);
      $synced = $provider->syncAll();
      if (!empty($synced) && $interactive)
      {
        $io->text(sprintf(
          '  <info>%s</info>: synced %d command(s) to %s',
          $agent->displayName(),
          count($synced),
          $agent->commandsPath()
        ));
      }
    }

    if ($interactive)
    {
      $io->success('Local commands synced to all selected agents.');
    }
  }

  private function publishBuiltInCommands(bool $interactive, SymfonyStyle $io): void
  {
    $provider = new BuiltInCommandProvider(getcwd());
    $published = $provider->publishAll();

    $invalidCommands = $provider->getInvalidCommands();
    if (!empty($invalidCommands) && $interactive)
    {
      $io->warning('Some built-in commands failed validation and will be skipped:');
      foreach ($invalidCommands as $name => $result)
      {
        $io->text(sprintf('  <comment>%s</comment>:', $name));
        foreach ($result->errors as $error)
        {
          $io->text(sprintf('    - %s', $error));
        }
      }

      $io->newLine();
    }

    if (!empty($published) && $interactive)
    {
      $io->section('Built-in Commands');
      $io->text(sprintf('Published %d built-in command(s) to .ai/commands/:', count($published)));
      $io->listing($published);
    }
  }

  /**
   * @param array<Agent> $agents
   */
  private function writeGuidelines(array $agents, bool $interactive, SymfonyStyle $io): void
  {
    $basePath = getcwd();
    $writer = new GuidelineWriter($basePath);
    $guidelineAgents = array_filter(
      $agents,
      static fn(Agent $agent): bool => $agent instanceof SupportsGuidelines
    );

    if (empty($guidelineAgents))
    {
      return;
    }

    $written = [];
    foreach ($guidelineAgents as $agent)
    {
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

  private function installConfiguredCommands(bool $interactive, SymfonyStyle $io, OutputInterface $output): void
  {
    $repos = $this->config->getCommands();
    if (empty($repos))
    {
      return;
    }

    if ($interactive)
    {
      $io->section('GitHub Commands');
      $io->listing($repos);

      $proceed = $io->confirm('Install commands from these repositories?', true);
      if (!$proceed)
      {
        $io->comment('Skipped GitHub command installation.');
        return;
      }
    }

    $addCommand = $this->getApplication()->find('add:command');
    foreach ($repos as $repo)
    {
      $addInput = new ArrayInput([
        'repository' => $repo,
        '--no-interaction' => true,
      ]);

      $addCommand->run($addInput, $output);
    }
  }
}
