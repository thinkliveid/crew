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
use Thinkliveid\Crew\Concerns\DisplayHelper;
use Thinkliveid\Crew\Contracts\SupportsGuidelines;
use Thinkliveid\Crew\Contracts\SupportsTeams;
use Thinkliveid\Crew\Install\AgentsDetector;
use Thinkliveid\Crew\Install\Agents\Agent;
use Thinkliveid\Crew\Install\GuidelineWriter;
use Thinkliveid\Crew\Teams\BuiltIn\BuiltInTeamProvider;
use Thinkliveid\Crew\Teams\Local\LocalTeamProvider;
use Thinkliveid\Crew\Support\Config;

#[AsCommand(
  name: 'install:team',
  description: 'Detect agents, sync local team templates, and store configuration'
)]
class InstallTeamCommand extends Command
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
      $this->displayBoostHeader('Teams', basename(getcwd()));
    }

    if ($skipDetection)
    {
      $selectedAgentNames = $this->config->getAgents();
    }
    else
    {
      // --- Discover agents ---
      $this->discoverEnvironment();

      // --- Select agents ---
      $selectedAgentNames = $this->selectAgents($input, $io, $interactive);

      // --- Store agents in config ---
      if (!empty($selectedAgentNames))
      {
        $this->config->setAgents($selectedAgentNames);

        if ($interactive)
        {
          $io->success(sprintf('Saved %d agent(s): %s', count($selectedAgentNames), implode(', ', $selectedAgentNames)));
        }
      }
    }

    // --- Publish built-in teams to .ai/teams/ ---
    $selectedAgents = $this->resolveAgentInstances($selectedAgentNames);
    $this->publishBuiltInTeams($interactive, $io);

    // --- Local team sync per agent ---
    $this->syncLocalTeamsForAgents($selectedAgents, $interactive, $io);

    // --- GitHub team install from crew.json ---
    $this->installConfiguredTeams($interactive, $io, $output);

    // --- Write guidelines ---
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
  private function syncLocalTeamsForAgents(array $agents, bool $interactive, SymfonyStyle $io): void
  {
    $basePath = getcwd();

    $sourceProvider = new LocalTeamProvider($basePath);
    $localTeams = $sourceProvider->discoverTeams();

    $invalidTeams = $sourceProvider->getInvalidTeams();

    if (!empty($invalidTeams) && $interactive)
    {
      $io->warning('Some local teams failed validation and will be skipped:');
      foreach ($invalidTeams as $name => $result)
      {
        $io->text(sprintf('  <comment>%s</comment>:', $name));
        foreach ($result->errors as $error)
        {
          $io->text(sprintf('    - %s', $error));
        }
      }

      $io->newLine();
    }

    if (empty($localTeams))
    {
      if ($interactive)
      {
        $io->info('No valid local teams found in .ai/teams/.');
      }
      return;
    }

    $teamAgents = array_filter($agents, fn(Agent $agent): bool => $agent instanceof SupportsTeams);
    if (empty($teamAgents))
    {
      if ($interactive)
      {
        $io->warning('No selected agents support teams.');
      }
      return;
    }

    if ($interactive)
    {
      $io->section('Local Teams');
      $io->listing($localTeams);

      $agentNames = array_map(fn(Agent $a): string => $a->displayName(), $teamAgents);
      $proceed = $io->confirm(
        sprintf('Sync these local teams to %s?', implode(', ', $agentNames)),
        true
      );

      if (!$proceed)
      {
        $io->comment('Skipped local team sync.');
        return;
      }
    }

    foreach ($teamAgents as $agent)
    {
      /** @var Agent&SupportsTeams $agent */
      $targetPath = $basePath . '/' . $agent->teamsPath();
      $provider = new LocalTeamProvider($basePath, $targetPath);
      $synced = $provider->syncAll();
      if (!empty($synced) && $interactive)
      {
        $io->text(sprintf(
          '  <info>%s</info>: synced %d team(s) to %s',
          $agent->displayName(),
          count($synced),
          $agent->teamsPath()
        ));
      }
    }

    if ($interactive)
    {
      $io->success('Local teams synced to all selected agents.');
    }
  }

  private function publishBuiltInTeams(bool $interactive, SymfonyStyle $io): void
  {
    $provider = new BuiltInTeamProvider(getcwd());
    $published = $provider->publishAll();

    $invalidTeams = $provider->getInvalidTeams();
    if (!empty($invalidTeams) && $interactive)
    {
      $io->warning('Some built-in teams failed validation and will be skipped:');
      foreach ($invalidTeams as $name => $result)
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
      $io->section('Built-in Teams');
      $io->text(sprintf('Published %d built-in team(s) to .ai/teams/:', count($published)));
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

  private function installConfiguredTeams(bool $interactive, SymfonyStyle $io, OutputInterface $output): void
  {
    $repos = $this->config->getTeams();
    if (empty($repos))
    {
      return;
    }

    if ($interactive)
    {
      $io->section('GitHub Teams');
      $io->listing($repos);

      $proceed = $io->confirm('Install teams from these repositories?', true);
      if (!$proceed)
      {
        $io->comment('Skipped GitHub team installation.');
        return;
      }
    }

    $addCommand = $this->getApplication()->find('add:team');
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
