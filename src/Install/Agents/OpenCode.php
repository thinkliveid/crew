<?php

declare(strict_types=1);

namespace Thinkliveid\Crew\Install\Agents;

use Thinkliveid\Crew\Contracts\SupportsGuidelines;
use Thinkliveid\Crew\Contracts\SupportsMcp;
use Thinkliveid\Crew\Contracts\SupportsSkills;
use Thinkliveid\Crew\Enums\McpInstallationStrategy;
use Thinkliveid\Crew\Enums\Platform;

class OpenCode extends Agent implements SupportsGuidelines, SupportsMcp, SupportsSkills
{
  public function name(): string
  {
    return 'opencode';
  }

  public function displayName(): string
  {
    return 'OpenCode';
  }

  public function systemDetectionConfig(Platform $platform): array
  {
    return match ($platform)
    {
      Platform::Darwin, Platform::Linux => [
        'command' => 'command -v opencode',
      ],
      Platform::Windows => [
        'command' => 'cmd /c where opencode 2>nul',
      ],
    };
  }

  public function projectDetectionConfig(): array
  {
    return [
      'files' => ['AGENTS.md', 'opencode.json'],
    ];
  }

  public function mcpInstallationStrategy(): McpInstallationStrategy
  {
    return McpInstallationStrategy::File;
  }

  public function mcpConfigPath(): string
  {
    return 'opencode.json';
  }

  public function guidelinesPath(): string
  {
    return 'AGENTS.md';
  }

  public function mcpConfigKey(): string
  {
    return 'mcp';
  }

  /** {@inheritDoc} */
  public function defaultMcpConfig(): array
  {
    return [
      '$schema' => 'https://opencode.ai/config.json',
    ];
  }

  /** {@inheritDoc} */
  public function httpMcpServerConfig(string $url): array
  {
    return [
      'type' => 'remote',
      'enabled' => true,
      'url' => $url,
    ];
  }

  /** {@inheritDoc} */
  public function mcpServerConfig(string $command, array $args = [], array $env = []): array
  {
    return [
      'type' => 'local',
      'enabled' => true,
      'command' => [$command, ...$args],
      'environment' => $env,
    ];
  }

  public function skillsPath(): string
  {
    return '.agents/skills';
  }
}
