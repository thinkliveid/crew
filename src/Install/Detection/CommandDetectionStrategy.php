<?php

declare(strict_types=1);

namespace Thinkliveid\Crew\Install\Detection;

use Symfony\Component\Process\Process;
use Thinkliveid\Crew\Enums\Platform;
use Thinkliveid\Crew\Install\Contracts\DetectionStrategy;

class CommandDetectionStrategy implements DetectionStrategy
{
  public function detect(array $config, ?Platform $platform = null): bool
  {
    if (!isset($config['command']))
    {
      return false;
    }

    $process = Process::fromShellCommandline($config['command']);
    $process->run();
    return $process->isSuccessful();
  }
}