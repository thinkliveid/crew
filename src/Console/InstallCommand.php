<?php

declare(strict_types=1);

namespace Thinkliveid\Crew\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Input\ArrayInput;
use Thinkliveid\Crew\Concerns\DisplayHelper;
use Thinkliveid\Crew\Contracts\SupportsGuidelines;
use Thinkliveid\Crew\Contracts\SupportsSkills;
use Thinkliveid\Crew\Install\AgentsDetector;
use Thinkliveid\Crew\Install\Agents\Agent;
use Thinkliveid\Crew\Install\GuidelineWriter;
use Thinkliveid\Crew\Skills\BuiltIn\BuiltInSkillProvider;
use Thinkliveid\Crew\Skills\Local\LocalSkillProvider;
use Thinkliveid\Crew\Support\Config;

#[AsCommand(
  name: 'install:skill',
  description: 'Detect agents, sync local skills, and store configuration'
)]
class InstallCommand extends Command
{
  use DisplayHelper;

  /** @var array<string> */
  private array $systemInstalledAgents = [];

  /** @var array<string> */
  private array $projectInstalledAgents = [];

  public function __construct(
    private readonly Config $config,
    private readonly AgentsDetector $agentsDetector,
  )
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
      $this->displayBoostHeader('Skills', basename(getcwd()));
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

    // --- Publish built-in skills to .ai/skills/ ---
    $selectedAgents = $this->resolveAgentInstances($selectedAgentNames);
    $this->publishBuiltInSkills($interactive, $io);

    // --- Local skill sync per agent ---
    $this->syncLocalSkillsForAgents($selectedAgents, $interactive, $io);

    // --- GitHub skill install from crew.json ---
    $this->installConfiguredSkills($interactive, $io, $output);

    // --- Write skill activation to guideline files ---
    $this->writeSkillGuidelines($selectedAgents, $interactive, $io);

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

    // Build name => displayName map
    $options = [];
    foreach ($allAgents as $agent)
    {
      $options[$agent->name()] = $agent->displayName();
    }

    asort($options);

    // Determine defaults: saved config first, then auto-detected
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

    // Non-interactive: use defaults without prompting
    if (!$interactive)
    {
      return $defaults;
    }

    // Interactive: only show detected/saved agents
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
   * Resolve agent instances from names via AgentsDetector.
   *
   * @param array<string> $names
   * @return array<Agent>
   */
  private function resolveAgentInstances(array $names): array
  {
    $allAgents = $this->agentsDetector->getAgents();

    return array_values(array_filter(
      $allAgents,
      fn(Agent $agent): bool => in_array($agent->name(), $names, true)
    ));
  }

  /**
   * Sync local skills to each selected agent that supports skills.
   *
   * @param array<Agent> $agents
   */
  private function syncLocalSkillsForAgents(array $agents, bool $interactive, SymfonyStyle $io): void
  {
    $basePath = getcwd();

    // Discover local skills once (source is always .ai/skills)
    $sourceProvider = new LocalSkillProvider($basePath);
    $localSkills = $sourceProvider->discoverSkills();

    if (empty($localSkills))
    {
      if ($interactive)
      {
        $io->info('No local skills found in .ai/skills/.');
      }
      return;
    }

    // Filter agents that support skills
    $skillAgents = array_filter($agents, fn(Agent $agent): bool => $agent instanceof SupportsSkills);

    if (empty($skillAgents))
    {
      if ($interactive)
      {
        $io->warning('No selected agents support skills.');
      }
      return;
    }

    if ($interactive)
    {
      $io->section('Local Skills');
      $io->listing($localSkills);

      $agentNames = array_map(fn(Agent $a): string => $a->displayName(), $skillAgents);
      $proceed = $io->confirm(
        sprintf('Sync these local skills to %s?', implode(', ', $agentNames)),
        true
      );

      if (!$proceed)
      {
        $io->comment('Skipped local skill sync.');
        return;
      }
    }

    foreach ($skillAgents as $agent)
    {
      /** @var Agent&SupportsSkills $agent */
      $targetPath = $basePath . '/' . $agent->skillsPath();
      $provider = new LocalSkillProvider($basePath, $targetPath);
      $synced = $provider->syncAll();

      if (!empty($synced) && $interactive)
      {
        $io->text(sprintf(
          '  <info>%s</info>: synced %d skill(s) to %s',
          $agent->displayName(),
          count($synced),
          $agent->skillsPath()
        ));
      }
    }

    if ($interactive)
    {
      $io->success('Local skills synced to all selected agents.');
    }
  }

  /**
   * Publish built-in skills from the package to the project's .ai/skills/ directory.
   */
  private function publishBuiltInSkills(bool $interactive, SymfonyStyle $io): void
  {
    $provider = new BuiltInSkillProvider(getcwd());
    $published = $provider->publishAll();

    if (!empty($published) && $interactive)
    {
      $io->section('Built-in Skills');
      $io->text(sprintf('Published %d built-in skill(s) to .ai/skills/:', count($published)));
      $io->listing($published);
    }
  }

  /**
   * Write skill activation to guideline files for agents that support guidelines.
   *
   * @param array<Agent> $agents
   */
  private function writeSkillGuidelines(array $agents, bool $interactive, SymfonyStyle $io): void
  {
    $basePath = getcwd();
    $writer = new GuidelineWriter($basePath);

    $guidelineAgents = array_filter(
      $agents,
      fn(Agent $agent): bool => $agent instanceof SupportsGuidelines
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

  /**
   * Install GitHub skills listed in crew.json via add:skill command.
   */
  private function installConfiguredSkills(bool $interactive, SymfonyStyle $io, OutputInterface $output): void
  {
    $repos = $this->config->getSkills();

    if (empty($repos))
    {
      return;
    }

    if ($interactive)
    {
      $io->section('GitHub Skills');
      $io->listing($repos);

      $proceed = $io->confirm('Install skills from these repositories?', true);

      if (!$proceed)
      {
        $io->comment('Skipped GitHub skill installation.');
        return;
      }
    }

    $addSkillCommand = $this->getApplication()->find('add:skill');

    foreach ($repos as $repo)
    {
      $addSkillInput = new ArrayInput([
        'repository' => $repo,
        '--no-interaction' => true,
      ]);

      $addSkillCommand->run($addSkillInput, $output);
    }
  }
}
