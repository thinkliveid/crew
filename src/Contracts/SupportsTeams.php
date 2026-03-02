<?php

declare(strict_types=1);

namespace Thinkliveid\Crew\Contracts;

/**
 * Contract for agents that support Team Templates
 */
interface SupportsTeams
{
  /**
   * Get the file path where team templates should be written.
   */
  public function teamsPath(): string;
}
