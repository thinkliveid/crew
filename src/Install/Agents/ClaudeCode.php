<?php

declare(strict_types=1);

namespace Thinkliveid\Crew\Install\Agents;

use Thinkliveid\Crew\Contracts\SupportsCommands;
use Thinkliveid\Crew\Contracts\SupportsGuidelines;
use Thinkliveid\Crew\Contracts\SupportsMcp;
use Thinkliveid\Crew\Contracts\SupportsSkills;
use Thinkliveid\Crew\Contracts\SupportsSubAgents;
use Thinkliveid\Crew\Enums\McpInstallationStrategy;
use Thinkliveid\Crew\Enums\Platform;

class ClaudeCode extends Agent implements SupportsCommands, SupportsGuidelines, SupportsMcp, SupportsSkills, SupportsSubAgents
{
  public function name(): string
  {
    return 'claude_code';
  }

  public function displayName(): string
  {
    return 'Claude Code';
  }

  public function systemDetectionConfig(Platform $platform): array
  {
    return match ($platform)
    {
      Platform::Darwin, Platform::Linux => [
        'command' => 'command -v claude',
      ],
      Platform::Windows                 => [
        'command' => 'cmd /c where claude 2>nul',
      ],
    };
  }

  public function projectDetectionConfig(): array
  {
    return [
      'paths' => ['.claude'],
      'files' => ['CLAUDE.md'],
    ];
  }

  public function mcpConfigPath(): string
  {
    return '.mcp.json';
  }

  public function guidelinesPath(): string
  {
    return 'CLAUDE.md';
  }

  public function skillsPath(): string
  {
    return '.claude/skills';
  }

  public function subAgentsPath(): string
  {
    return '.claude/agents';
  }

  public function commandsPath(): string
  {
    return '.claude/commands';
  }
}
