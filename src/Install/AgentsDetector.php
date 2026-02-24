<?php

declare(strict_types=1);

namespace Thinkliveid\Crew\Install;

use Thinkliveid\Crew\Enums\Platform;
use Thinkliveid\Crew\Install\Agents\Agent;
use Thinkliveid\Crew\Install\Agents\ClaudeCode;
use Thinkliveid\Crew\Install\Agents\Codex;
use Thinkliveid\Crew\Install\Agents\Copilot;
use Thinkliveid\Crew\Install\Agents\Cursor;
use Thinkliveid\Crew\Install\Agents\Gemini;
use Thinkliveid\Crew\Install\Agents\Junie;
use Thinkliveid\Crew\Install\Agents\OpenCode;
use Thinkliveid\Crew\Install\Detection\DetectionStrategyFactory;

class AgentsDetector
{
  /** @var array<class-string<Agent>> */
  private array $defaultAgentClasses = [
    ClaudeCode::class,
    Cursor::class,
    Copilot::class,
    Gemini::class,
    Junie::class,
    Codex::class,
    OpenCode::class,
  ];

  /**
   * @param array<class-string<Agent>>|null $agentClasses
   */
  public function __construct(
    private readonly DetectionStrategyFactory $strategyFactory,
    private readonly ?array                   $agentClasses = null,
  )
  {
  }

  /**
   * Detect installed agents on the current platform.
   *
   * @return array<string>
   */
  public function discoverSystemInstalledAgents(): array
  {
    $platform = Platform::current();

    $result = [];
    foreach ($this->getAgents() as $agent)
    {
      if ($agent->detectOnSystem($platform))
      {
        $result[] = $agent->name();
      }
    }

    return $result;
  }

  /**
   * Detect agents used in the current project.
   *
   * @return array<string>
   */
  public function discoverProjectInstalledAgents(string $basePath): array
  {
    $result = [];
    foreach ($this->getAgents() as $agent)
    {
      if ($agent->detectInProject($basePath))
      {
        $result[] = $agent->name();
      }
    }

    return $result;
  }

  /**
   * Get all registered agent instances.
   *
   * @return array<Agent>
   */
  public function getAgents(): array
  {
    $classes = $this->agentClasses ?? $this->defaultAgentClasses;

    return array_map(
      fn(string $className): Agent => new $className($this->strategyFactory),
      $classes
    );
  }
}
