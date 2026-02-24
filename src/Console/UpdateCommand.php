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
  name: 'update:skill',
  description: 'Update the Crew guidelines & skills to the latest guidance'
)]
class UpdateCommand extends Command
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
      $io->error('No crew.json found. Please run [php crew install:skill] first.');
      return Command::FAILURE;
    }

    $agents = $this->config->getAgents();

    if (empty($agents))
    {
      $io->error('No agents configured. Please run [php crew install:skill] first to set up your agents.');
      return Command::FAILURE;
    }

    $hasLocalSkills = is_dir(getcwd() . '/.ai/skills');
    $repos = $this->config->getSkills();

    if (empty($repos) && !$hasLocalSkills)
    {
      $io->info('No skills configured and no .ai/skills/ directory found. Nothing to update.');
      return Command::SUCCESS;
    }

    // Delegate to install:skill, skipping agent detection (use crew.json agents)
    $installCommand = $this->getApplication()->find('install:skill');
    $installInput = new ArrayInput([
      '--no-interaction' => true,
      '--skip-detection' => true,
    ]);

    $installCommand->run($installInput, $output);

    $io->success('Skills updated successfully.');

    return Command::SUCCESS;
  }
}
