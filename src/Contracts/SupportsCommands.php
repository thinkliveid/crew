<?php

declare(strict_types=1);

namespace Thinkliveid\Crew\Contracts;

/**
 * Contract for agents that support slash Commands
 */
interface SupportsCommands
{
  /**
   * Get the file path where commands should be written.
   */
  public function commandsPath(): string;
}
