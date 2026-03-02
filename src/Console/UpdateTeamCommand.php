<?php

declare(strict_types=1);

namespace Thinkliveid\Crew\Console;

use Thinkliveid\Crew\Support\Config;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
  name: 'update:team',
  description: 'Update the Crew team templates to the latest guidance'
)]
class UpdateTeamCommand extends Command
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
      $io->error('No crew.json found. Please run [php crew install:team] first.');
      return Command::FAILURE;
    }

    $agents = $this->config->getAgents();
    if (empty($agents))
    {
      $io->error('No agents configured. Please run [php crew install:team] first to set up your agents.');
      return Command::FAILURE;
    }

    $hasLocalTeams = is_dir(getcwd() . '/.ai/teams');
    $repos = $this->config->getTeams();
    if (empty($repos) && !$hasLocalTeams)
    {
      $io->info('No teams configured and no .ai/teams/ directory found. Nothing to update.');
      return Command::SUCCESS;
    }

    $installCommand = $this->getApplication()->find('install:team');
    $installInput = new ArrayInput([
      '--no-interaction' => true,
      '--skip-detection' => true,
    ]);

    $installCommand->run($installInput, $output);
    $io->success('Teams updated successfully.');

    return Command::SUCCESS;
  }
}
