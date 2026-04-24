<?php

declare(strict_types=1);

namespace Thinkliveid\Crew\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
  name: 'init',
  description: 'Install skills, sub-agents, and team templates in one pass'
)]
class InitCommand extends Command
{
  protected function execute(InputInterface $input, OutputInterface $output): int
  {
    $io = new SymfonyStyle($input, $output);
    $interactive = $input->isInteractive();

    $this->ensureDefaultTeam($io);

    $steps = [
      ['name' => 'install:skill',    'skipDetection' => false],
      ['name' => 'install:subagent', 'skipDetection' => true],
      ['name' => 'install:team',     'skipDetection' => true],
    ];

    foreach ($steps as $step)
    {
      $command = $this->getApplication()->find($step['name']);

      $args = [];
      if ($step['skipDetection'])
      {
        $args['--skip-detection'] = true;
      }

      $childInput = new ArrayInput($args);
      $childInput->setInteractive($interactive);

      $status = $command->run($childInput, $output);
      if ($status !== Command::SUCCESS)
      {
        $io->error(sprintf('%s failed with status %d', $step['name'], $status));
        return $status;
      }
    }

    return Command::SUCCESS;
  }

  /**
   * Scaffold a default team named after the project folder on fresh init.
   *
   * Skipped when .ai/teams/ already contains any team.
   */
  private function ensureDefaultTeam(SymfonyStyle $io): void
  {
    $teamsDir = getcwd() . '/.ai/teams';

    if ($this->hasAnyTeam($teamsDir))
    {
      return;
    }

    $name = $this->sanitizeTeamName(basename(getcwd()));
    if ($name === '')
    {
      return;
    }

    $targetDir = $teamsDir . '/' . $name;
    if (is_dir($targetDir))
    {
      return;
    }

    if (!is_dir($teamsDir) && !mkdir($teamsDir, 0755, true) && !is_dir($teamsDir))
    {
      return;
    }

    if (!mkdir($targetDir, 0755, true) && !is_dir($targetDir))
    {
      return;
    }

    $displayName = ucwords(str_replace('-', ' ', $name));
    $content = <<<MD
---
name: {$name}
description: {$name}
---

# {$displayName}

{$name}

## Members

<!-- Define team members and their roles here -->
MD;

    file_put_contents($targetDir . '/TEAM.md', $content . "\n");

    $io->text(sprintf('Created default team <info>%s</info> at .ai/teams/%s/TEAM.md', $name, $name));
  }

  private function hasAnyTeam(string $teamsDir): bool
  {
    if (!is_dir($teamsDir))
    {
      return false;
    }

    $entries = scandir($teamsDir);
    if ($entries === false)
    {
      return false;
    }

    foreach ($entries as $entry)
    {
      if ($entry === '.' || $entry === '..')
      {
        continue;
      }

      if (is_dir($teamsDir . '/' . $entry))
      {
        return true;
      }
    }

    return false;
  }

  private function sanitizeTeamName(string $raw): string
  {
    $name = strtolower($raw);
    $name = preg_replace('/[^a-z0-9]+/', '-', $name) ?? '';
    $name = preg_replace('/-+/', '-', $name) ?? '';
    $name = trim($name, '-');

    if (strlen($name) > 64)
    {
      $name = rtrim(substr($name, 0, 64), '-');
    }

    return $name;
  }
}
