<?php

declare(strict_types=1);

namespace Thinkliveid\Crew\Contracts;

/**
 * Contract for agents that support Sub-agents
 */
interface SupportsSubAgents
{
  /**
   * Get the file path where sub-agents should be written.
   */
  public function subAgentsPath(): string;
}
