<?php

declare(strict_types=1);

namespace Thinkliveid\Crew\Install\Agents;

use Thinkliveid\Crew\Contracts\SupportsGuidelines;
use Thinkliveid\Crew\Contracts\SupportsMcp;
use Thinkliveid\Crew\Contracts\SupportsSkills;
use Thinkliveid\Crew\Enums\McpInstallationStrategy;
use Thinkliveid\Crew\Enums\Platform;

class Codex extends Agent implements SupportsGuidelines, SupportsMcp, SupportsSkills
{
  public function name(): string
  {
    return 'codex';
  }

  public function displayName(): string
  {
    return 'Codex';
  }

  public function systemDetectionConfig(Platform $platform): array
  {
    return match ($platform)
    {
      Platform::Darwin, Platform::Linux => [
        'command' => 'which codex',
      ],
      Platform::Windows                 => [
        'command' => 'cmd /c where codex 2>nul',
      ],
    };
  }

  public function projectDetectionConfig(): array
  {
    return [
      'paths' => ['.codex'],
      'files' => ['AGENTS.md', '.codex/config.toml'],
    ];
  }

  public function guidelinesPath(): string
  {
    return 'AGENTS.md';
  }

  public function mcpConfigPath(): string
  {
    return '.codex/config.toml';
  }

  public function mcpConfigKey(): string
  {
    return 'mcp_servers';
  }

  /** {@inheritDoc} */
  public function httpMcpServerConfig(string $url): array
  {
    return [
      'command' => 'npx',
      'args' => ['-y', 'mcp-remote', $url],
    ];
  }

  /** {@inheritDoc} */
  public function mcpServerConfig(string $command, array $args = [], array $env = []): array
  {
    return array_filter([
      'command' => $command,
      'args' => $args,
      'cwd' => getcwd(),
      'env' => $env,
    ], static fn($value): bool => !in_array($value, [[], null, ''], true));
  }

  public function skillsPath(): string
  {
    return '.agents/skills';
  }
}
