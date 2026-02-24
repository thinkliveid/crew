<?php

declare(strict_types=1);

namespace Thinkliveid\Crew\Install\Agents;

use Thinkliveid\Crew\Contracts\SupportsGuidelines;
use Thinkliveid\Crew\Contracts\SupportsMcp;
use Thinkliveid\Crew\Contracts\SupportsSkills;
use Thinkliveid\Crew\Enums\Platform;

class Cursor extends Agent implements SupportsGuidelines, SupportsMcp, SupportsSkills
{
  public function name(): string
  {
    return 'cursor';
  }

  public function displayName(): string
  {
    return 'Cursor';
  }

  public function systemDetectionConfig(Platform $platform): array
  {
    return match ($platform)
    {
      Platform::Darwin => [
        'paths' => ['/Applications/Cursor.app'],
      ],
      Platform::Linux => [
        'paths' => [
          '/opt/cursor',
          '/usr/local/bin/cursor',
          '~/.local/bin/cursor',
        ],
      ],
      Platform::Windows => [
        'paths' => [
          '%ProgramFiles%\\Cursor',
          '%LOCALAPPDATA%\\Programs\\Cursor',
        ],
      ],
    };
  }

  public function projectDetectionConfig(): array
  {
    return [
      'paths' => ['.cursor'],
    ];
  }

  public function mcpConfigPath(): string
  {
    return '.cursor/mcp.json';
  }

  /** {@inheritDoc} */
  public function httpMcpServerConfig(string $url): array
  {
    return [
      'command' => 'npx',
      'args' => ['-y', 'mcp-remote', $url],
    ];
  }

  public function guidelinesPath(): string
  {
    return 'AGENTS.md';
  }

  public function skillsPath(): string
  {
    return '.cursor/skills';
  }
}
