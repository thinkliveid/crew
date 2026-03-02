<?php

declare(strict_types=1);

namespace Thinkliveid\Crew\SubAgents\Remote;

class RemoteSubAgent
{
  public function __construct(public string $name, public string $repo, public string $path)
  {
  }
}
