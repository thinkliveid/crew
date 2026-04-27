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
  name: 'sync',
  description: 'Update skills, sub-agents, and slash commands in one pass'
)]
class SyncCommand extends Command
{
  protected function execute(InputInterface $input, OutputInterface $output): int
  {
    $io = new SymfonyStyle($input, $output);
    $interactive = $input->isInteractive();

    $steps = ['update:skill', 'update:subagent', 'update:command'];

    foreach ($steps as $name)
    {
      $command = $this->getApplication()->find($name);

      $childInput = new ArrayInput([]);
      $childInput->setInteractive($interactive);

      $status = $command->run($childInput, $output);
      if ($status !== Command::SUCCESS)
      {
        $io->error(sprintf('%s failed with status %d', $name, $status));
        return $status;
      }
    }

    return Command::SUCCESS;
  }
}