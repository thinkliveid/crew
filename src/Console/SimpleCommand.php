<?php

declare(strict_types=1);

namespace Thinkliveid\Crew\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
  name: 'simple',
  description: 'Mengecek koneksi database di Slim Framework',
  hidden: false
)]
class SimpleCommand extends Command
{
  protected function execute(InputInterface $input, OutputInterface $output): int
  {
    $output->writeln('<info>Mengecek koneksi...</info>');
    $output->writeln('<comment>Koneksi Berhasil!</comment>');
    return Command::SUCCESS;
  }
}