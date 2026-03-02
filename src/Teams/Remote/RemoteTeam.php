<?php

declare(strict_types=1);

namespace Thinkliveid\Crew\Teams\Remote;

class RemoteTeam
{
  public function __construct(public string $name, public string $repo, public string $path)
  {
  }
}
