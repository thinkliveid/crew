<?php

declare(strict_types=1);

namespace Thinkliveid\Crew\Commands\Remote;

class RemoteCommand
{
  public function __construct(public string $name, public string $repo, public string $path)
  {
  }
}
