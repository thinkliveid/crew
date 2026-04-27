<?php

declare(strict_types=1);

namespace Thinkliveid\Crew\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Thinkliveid\Crew\Support\Config;

#[AsCommand(
  name: 'update:command',
  description: 'Update the Crew slash commands to the latest guidance'
)]
class UpdateCommandCommand extends Command
{
  public function __construct(protected Config $config)
  {
    parent::__construct();
  }

  protected function execute(InputInterface $input, OutputInterface $output): int
  {
    $io = new SymfonyStyle($input, $output);
    if (!$this->config->isValid())
    {
      $io->error('No crew.json found. Please run [php crew install:command] first.');
      return Command::FAILURE;
    }

    $agents = $this->config->getAgents();
    if (empty($agents))
    {
      $io->error('No agents configured. Please run [php crew install:command] first to set up your agents.');
      return Command::FAILURE;
    }

    $hasLocalCommands = is_dir(getcwd() . '/.ai/commands');
    $repos = $this->config->getCommands();
    if (empty($repos) && !$hasLocalCommands)
    {
      $io->info('No commands configured and no .ai/commands/ directory found. Nothing to update.');
      return Command::SUCCESS;
    }

    $installCommand = $this->getApplication()->find('install:command');
    $installInput = new ArrayInput([
      '--no-interaction' => true,
      '--skip-detection' => true,
    ]);

    $installCommand->run($installInput, $output);
    $io->success('Commands updated successfully.');

    return Command::SUCCESS;
  }
}
