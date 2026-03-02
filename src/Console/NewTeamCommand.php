<?php

declare(strict_types=1);

namespace Thinkliveid\Crew\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Thinkliveid\Crew\Concerns\DisplayHelper;

#[AsCommand(
  name: 'new:team',
  description: 'Create a new team scaffold in .ai/teams/'
)]
class NewTeamCommand extends Command
{
  use DisplayHelper;

  private const NAME_PATTERN = '/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/';
  private const NAME_MAX_LENGTH = 64;
  private const DESCRIPTION_MAX_LENGTH = 1024;

  protected function configure(): void
  {
    $this->addArgument('name', InputArgument::OPTIONAL, 'Team name (lowercase alphanumeric + hyphens)');
  }

  protected function execute(InputInterface $input, OutputInterface $output): int
  {
    $this->output = $output;
    $io = new SymfonyStyle($input, $output);

    $this->displayBoostHeader('New Team', basename(getcwd()));

    $name = $this->resolveName($input, $io);
    if ($name === null)
    {
      return Command::FAILURE;
    }

    $targetDir = getcwd() . '/.ai/teams/' . $name;
    if (is_dir($targetDir))
    {
      $io->error("Team '{$name}' already exists at .ai/teams/{$name}/");

      return Command::FAILURE;
    }

    $description = $io->ask('Description', null, function (?string $value): string {
      $value = trim($value ?? '');
      if ($value === '')
      {
        throw new \RuntimeException('Description is required.');
      }

      if (strlen($value) > self::DESCRIPTION_MAX_LENGTH)
      {
        throw new \RuntimeException('Description must not exceed ' . self::DESCRIPTION_MAX_LENGTH . ' characters.');
      }

      return $value;
    });

    $displayName = $this->toDisplayName($name);
    $content = <<<MD
---
name: {$name}
description: {$description}
---

# {$displayName}

{$description}

## Members

<!-- Define team members and their roles here -->
MD;

    if (!is_dir(dirname($targetDir)))
    {
      mkdir(dirname($targetDir), 0755, true);
    }

    mkdir($targetDir, 0755, true);
    file_put_contents($targetDir . '/TEAM.md', $content . "\n");

    $io->success("Created team '{$name}' at .ai/teams/{$name}/TEAM.md");

    return Command::SUCCESS;
  }

  private function resolveName(InputInterface $input, SymfonyStyle $io): ?string
  {
    $name = $input->getArgument('name');

    if ($name !== null)
    {
      if (!$this->isValidName($name))
      {
        $io->error("Invalid name '{$name}'. Must be 1-" . self::NAME_MAX_LENGTH . " lowercase alphanumeric characters and hyphens, no leading/trailing/consecutive hyphens.");

        return null;
      }

      return $name;
    }

    return $io->ask('Team name', null, function (?string $value): string {
      $value = trim($value ?? '');
      if (!$this->isValidName($value))
      {
        throw new \RuntimeException("Invalid name. Must be 1-" . self::NAME_MAX_LENGTH . " lowercase alphanumeric characters and hyphens, no leading/trailing/consecutive hyphens.");
      }

      return $value;
    });
  }

  private function isValidName(string $name): bool
  {
    return strlen($name) >= 1
      && strlen($name) <= self::NAME_MAX_LENGTH
      && preg_match(self::NAME_PATTERN, $name) === 1
      && strpos($name, '--') === false;
  }

  private function toDisplayName(string $name): string
  {
    return ucwords(str_replace('-', ' ', $name));
  }
}
