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
  description: 'Install skills and sub-agents in one pass'
)]
class InitCommand extends Command
{
  protected function execute(InputInterface $input, OutputInterface $output): int
  {
    $io = new SymfonyStyle($input, $output);
    $interactive = $input->isInteractive();

    $steps = [
      ['name' => 'install:skill',    'skipDetection' => false],
      ['name' => 'install:subagent', 'skipDetection' => true],
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
}
