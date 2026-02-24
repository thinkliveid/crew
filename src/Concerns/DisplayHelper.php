<?php

declare(strict_types=1);

namespace Thinkliveid\Crew\Concerns;

use Symfony\Component\Console\Output\OutputInterface;
use Thinkliveid\Crew\Enums\Theme;

trait DisplayHelper
{
  protected ?Theme $theme = null;
  protected ?OutputInterface $output = null;

  protected function initTheme(?Theme $theme = null): void
  {
    $this->theme = $theme ?? Theme::random();
  }

  protected function displayBoostHeader(string $featureName, string $projectName, ?Theme $theme = null): void
  {
    $this->initTheme($theme);
    $this->displayGradientLogo();
    $this->displayTagline($featureName);
    $this->displayNote($projectName);
  }

  protected function displayGradientLogo(): void
  {
    $lines = [
      ' ██████╗ ██████╗ ███████╗██╗    ██╗',
      '██╔════╝ ██╔══██╗██╔════╝██║    ██║',
      '██║      ██████╔╝█████╗  ██║ █╗ ██║',
      '██║      ██╔══██╗██╔══╝  ██║███╗██║',
      '╚██████╗ ██║  ██║███████╗╚███╔███╔╝',
      ' ╚═════╝ ╚═╝  ╚═╝╚══════╝ ╚══╝╚══╝ ',
    ];

    $gradient = $this->theme->gradient();
    $this->newLine();
    foreach ($lines as $index => $line)
    {
      $this->output->writeln($this->ansi256Fg($gradient[$index], $line));
    }

    $this->newLine();
  }

  protected function displayTagline(string $featureName): void
  {
    $tagline = " ✦ Crew :: {$featureName} :: We Must Faster ✦ ";
    $this->output->writeln($this->displayBadge($tagline));
  }

  protected function displayNote(string $projectName): void
  {
    $badge = $this->displayBadge($projectName);
    $this->output->writeln("  Let's give {$badge} a crew");
    $this->newLine();
  }

  protected function displayOutro(string $text, string $link = '', int $terminalWidth = 80): void
  {
    $visibleText = preg_replace('/\x1b\[[0-9;]*m|\x1b\]8;;[^\x07]*\x07|\x1b\]8;;\x1b\\\\/', '', $text . $link) ?? '';
    $visualWidth = mb_strwidth($visibleText);
    $paddingLength = (int)(floor(($terminalWidth - $visualWidth) / 2)) - 2;
    $padding = str_repeat(' ', max(0, $paddingLength));

    $this->output->writeln("\e[48;5;{$this->theme->primary()}m\033[2K{$padding}\e[30m\e[1m{$text}{$link}\e[0m");
    $this->newLine();
  }

  protected function ansi256Fg(int $color, string $text): string
  {
    return "\e[38;5;{$color}m{$text}\e[0m";
  }

  protected function displayBadge(string $text): string
  {
    return "\e[48;5;{$this->theme->primary()}m\e[30m\e[1m{$text}\e[0m";
  }

  protected function hyperlink(string $label, string $url): string
  {
    return "\033]8;;{$url}\007{$label}\033]8;;\033\\";
  }

  protected function newLine($count = 1): static
  {
    for ($i = 0; $i < $count; $i++)
    {
      $this->output->writeln('');
    }

    return $this;
  }
}
