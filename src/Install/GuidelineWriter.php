<?php

declare(strict_types=1);

namespace Thinkliveid\Crew\Install;

use Thinkliveid\Crew\Commands\CommandValidator;
use Thinkliveid\Crew\Contracts\SupportsCommands;
use Thinkliveid\Crew\Contracts\SupportsGuidelines;
use Thinkliveid\Crew\Contracts\SupportsSubAgents;
use Thinkliveid\Crew\Install\Agents\Agent;
use Thinkliveid\Crew\Skills\SkillValidator;
use Thinkliveid\Crew\SubAgents\SubAgentValidator;

class GuidelineWriter
{
  private const string TAG_OPEN = '<crew-guidelines>';
  private const string TAG_CLOSE = '</crew-guidelines>';

  protected SkillValidator $validator;
  protected SubAgentValidator $subAgentValidator;
  protected CommandValidator $commandValidator;

  public function __construct(protected string $basePath)
  {
    $this->validator = new SkillValidator();
    $this->subAgentValidator = new SubAgentValidator();
    $this->commandValidator = new CommandValidator();
  }

  /**
   * Write a managed crew-guidelines block to an agent's guideline file.
   * Contains only the skills section (backward-compatible).
   *
   * @param Agent&SupportsGuidelines $agent
   * @param array<int, array{name: string, description: string, path: string}> $skills
   * @return bool
   */
  public function write(Agent&SupportsGuidelines $agent, array $skills): bool
  {
    if (empty($skills))
    {
      return false;
    }

    $filePath = $this->basePath . '/' . $agent->guidelinesPath();
    $block = $this->buildBlock($skills);
    $block = $agent->transformGuidelines($block);

    $existingContent = file_exists($filePath) ? file_get_contents($filePath) : '';
    if ($existingContent === false)
    {
      $existingContent = '';
    }

    $newContent = $this->mergeContent($existingContent, $block, $agent->frontmatter());
    $dir = dirname($filePath);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir))
    {
      return false;
    }

    return file_put_contents($filePath, $newContent) !== false;
  }

  /**
   * Write all content types (skills, sub-agents, commands) in one atomic <crew-guidelines> block.
   *
   * @param Agent&SupportsGuidelines $agent
   * @param array<int, array{name: string, description: string, path: string}> $skills
   * @param array<int, array{name: string, description: string, path: string}> $subAgents
   * @param array<int, array{name: string, description: string, path: string}> $commands
   * @return bool
   */
  public function writeAll(Agent&SupportsGuidelines $agent, array $skills, array $subAgents = [], array $commands = []): bool
  {
    if (empty($skills) && empty($subAgents) && empty($commands))
    {
      return false;
    }

    $filePath = $this->basePath . '/' . $agent->guidelinesPath();
    $block = $this->buildFullBlock($skills, $subAgents, $commands);
    $block = $agent->transformGuidelines($block);

    $existingContent = file_exists($filePath) ? file_get_contents($filePath) : '';
    if ($existingContent === false)
    {
      $existingContent = '';
    }

    $newContent = $this->mergeContent($existingContent, $block, $agent->frontmatter());
    $dir = dirname($filePath);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir))
    {
      return false;
    }

    return file_put_contents($filePath, $newContent) !== false;
  }

  /**
   * Build the managed block content (skills only, backward-compatible).
   *
   * @param array<int, array{name: string, description: string, path: string}> $skills
   */
  protected function buildBlock(array $skills): string
  {
    return $this->buildFullBlock($skills, [], []);
  }

  /**
   * Build the managed block with all content types.
   *
   * @param array<int, array{name: string, description: string, path: string}> $skills
   * @param array<int, array{name: string, description: string, path: string}> $subAgents
   * @param array<int, array{name: string, description: string, path: string}> $commands
   */
  protected function buildFullBlock(array $skills, array $subAgents, array $commands = []): string
  {
    $lines = [];
    $lines[] = self::TAG_OPEN;

    if (!empty($skills))
    {
      $lines[] = '## Skills';
      $lines[] = '';
      $lines[] = 'This project has the following skills installed:';
      $lines[] = '';

      foreach ($skills as $skill)
      {
        $lines[] = sprintf('- `%s` — %s (path: %s)', $skill['name'], $skill['description'], $skill['path']);
      }

      $lines[] = '';
    }

    if (!empty($subAgents))
    {
      $lines[] = '## Sub-agents';
      $lines[] = '';
      $lines[] = 'This project has the following sub-agents installed:';
      $lines[] = '';

      foreach ($subAgents as $agent)
      {
        $lines[] = sprintf('- `%s` — %s (path: %s)', $agent['name'], $agent['description'], $agent['path']);
      }

      $lines[] = '';
    }

    if (!empty($commands))
    {
      $lines[] = '## Commands';
      $lines[] = '';
      $lines[] = 'This project has the following slash commands installed:';
      $lines[] = '';

      foreach ($commands as $command)
      {
        $lines[] = sprintf('- `/%s` — %s (path: %s)', $command['name'], $command['description'], $command['path']);
      }

      $lines[] = '';
    }

    $lines[] = self::TAG_CLOSE;

    return implode("\n", $lines);
  }

  /**
   * Merge the managed block into the existing file content.
   */
  protected function mergeContent(string $existing, string $block, bool $frontmatter): string
  {
    if (str_contains($existing, self::TAG_OPEN) && str_contains($existing, self::TAG_CLOSE))
    {
      $pattern = '/' . preg_quote(self::TAG_OPEN, '/') . '.*?' . preg_quote(self::TAG_CLOSE, '/') . '/s';

      return preg_replace($pattern, $block, $existing);
    }

    $trimmed = trim($existing);
    if ($trimmed !== '')
    {
      return $existing . "\n\n===\n\n" . $block . "\n";
    }

    if ($frontmatter)
    {
      return "---\n---\n\n" . $block . "\n";
    }

    return $block . "\n";
  }

  /**
   * Collect skill info from all discovered skills in the project .ai/skills/ directory.
   * The path is relative to agent's skill directory.
   *
   * @param Agent&SupportsGuidelines $agent
   * @return array<int, array{name: string, description: string, path: string}>
   */
  public function collectSkillInfo(Agent&SupportsGuidelines $agent): array
  {
    $skillsSourcePath = $this->basePath . '/.ai/skills';
    if (!is_dir($skillsSourcePath))
    {
      return [];
    }

    $entries = scandir($skillsSourcePath);
    if ($entries === false)
    {
      return [];
    }

    /** @var Agent&\Thinkliveid\Crew\Contracts\SupportsSkills $agent */
    $agentSkillsPath = $agent instanceof \Thinkliveid\Crew\Contracts\SupportsSkills
      ? $agent->skillsPath()
      : '.claude/skills';

    $skills = [];
    foreach ($entries as $entry)
    {
      if ($entry === '.' || $entry === '..')
      {
        continue;
      }

      $skillDir = $skillsSourcePath . '/' . $entry;
      if (!is_dir($skillDir))
      {
        continue;
      }

      $result = $this->validator->validate($skillDir);
      if (!$result->valid)
      {
        continue;
      }

      $skills[] = [
        'name' => $entry,
        'description' => $result->description() ?? 'No description available',
        'path' => $agentSkillsPath . '/' . $entry,
      ];
    }

    $agentSkillsFullPath = $this->basePath . '/' . $agentSkillsPath;
    if (is_dir($agentSkillsFullPath))
    {
      $agentEntries = scandir($agentSkillsFullPath);
      if ($agentEntries !== false)
      {
        $existingNames = array_column($skills, 'name');
        foreach ($agentEntries as $entry)
        {
          if ($entry === '.' || $entry === '..')
          {
            continue;
          }

          if (in_array($entry, $existingNames, true))
          {
            continue;
          }

          $skillDir = $agentSkillsFullPath . '/' . $entry;
          if (!is_dir($skillDir))
          {
            continue;
          }

          $result = $this->validator->validate($skillDir);
          if (!$result->valid)
          {
            continue;
          }

          $skills[] = [
            'name' => $entry,
            'description' => $result->description() ?? 'No description available',
            'path' => $agentSkillsPath . '/' . $entry,
          ];
        }
      }
    }

    usort($skills, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));

    return $skills;
  }

  /**
   * Collect sub-agent info from .ai/agents/ and agent-specific directory.
   *
   * @param Agent&SupportsGuidelines $agent
   * @return array<int, array{name: string, description: string, path: string}>
   */
  public function collectSubAgentInfo(Agent&SupportsGuidelines $agent): array
  {
    $agentSubAgentsPath = $agent instanceof SupportsSubAgents
      ? $agent->subAgentsPath()
      : '.claude/agents';

    $subAgents = [];

    // Scan .ai/agents/ source
    $sourcePath = $this->basePath . '/.ai/agents';
    if (is_dir($sourcePath))
    {
      $entries = scandir($sourcePath);
      if ($entries !== false)
      {
        foreach ($entries as $entry)
        {
          if ($entry === '.' || $entry === '..')
          {
            continue;
          }

          $filePath = $sourcePath . '/' . $entry;
          if (!is_file($filePath) || !str_ends_with($entry, '.md'))
          {
            continue;
          }

          $result = $this->subAgentValidator->validate($filePath);
          if (!$result->valid)
          {
            continue;
          }

          $name = pathinfo($entry, PATHINFO_FILENAME);
          $subAgents[] = [
            'name' => $name,
            'description' => $result->description() ?? 'No description available',
            'path' => $agentSubAgentsPath . '/' . $entry,
          ];
        }
      }
    }

    // Scan agent-specific directory for additional agents
    $agentFullPath = $this->basePath . '/' . $agentSubAgentsPath;
    if (is_dir($agentFullPath))
    {
      $agentEntries = scandir($agentFullPath);
      if ($agentEntries !== false)
      {
        $existingNames = array_column($subAgents, 'name');
        foreach ($agentEntries as $entry)
        {
          if ($entry === '.' || $entry === '..')
          {
            continue;
          }

          $filePath = $agentFullPath . '/' . $entry;
          if (!is_file($filePath) || !str_ends_with($entry, '.md'))
          {
            continue;
          }

          $name = pathinfo($entry, PATHINFO_FILENAME);
          if (in_array($name, $existingNames, true))
          {
            continue;
          }

          $result = $this->subAgentValidator->validate($filePath);
          if (!$result->valid)
          {
            continue;
          }

          $subAgents[] = [
            'name' => $name,
            'description' => $result->description() ?? 'No description available',
            'path' => $agentSubAgentsPath . '/' . $entry,
          ];
        }
      }
    }

    usort($subAgents, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));

    return $subAgents;
  }

  /**
   * Collect slash command info from .ai/commands/ and agent-specific directory.
   *
   * @param Agent&SupportsGuidelines $agent
   * @return array<int, array{name: string, description: string, path: string}>
   */
  public function collectCommandInfo(Agent&SupportsGuidelines $agent): array
  {
    $agentCommandsPath = $agent instanceof SupportsCommands
      ? $agent->commandsPath()
      : '.claude/commands';

    $commands = [];

    $sourcePath = $this->basePath . '/.ai/commands';
    if (is_dir($sourcePath))
    {
      $entries = scandir($sourcePath);
      if ($entries !== false)
      {
        foreach ($entries as $entry)
        {
          if ($entry === '.' || $entry === '..')
          {
            continue;
          }

          $filePath = $sourcePath . '/' . $entry;
          if (!is_file($filePath) || !str_ends_with($entry, '.md'))
          {
            continue;
          }

          $result = $this->commandValidator->validate($filePath);
          if (!$result->valid)
          {
            continue;
          }

          $name = pathinfo($entry, PATHINFO_FILENAME);
          $commands[] = [
            'name' => $name,
            'description' => $result->description() ?? 'No description available',
            'path' => $agentCommandsPath . '/' . $entry,
          ];
        }
      }
    }

    $agentFullPath = $this->basePath . '/' . $agentCommandsPath;
    if (is_dir($agentFullPath))
    {
      $agentEntries = scandir($agentFullPath);
      if ($agentEntries !== false)
      {
        $existingNames = array_column($commands, 'name');
        foreach ($agentEntries as $entry)
        {
          if ($entry === '.' || $entry === '..')
          {
            continue;
          }

          $filePath = $agentFullPath . '/' . $entry;
          if (!is_file($filePath) || !str_ends_with($entry, '.md'))
          {
            continue;
          }

          $name = pathinfo($entry, PATHINFO_FILENAME);
          if (in_array($name, $existingNames, true))
          {
            continue;
          }

          $result = $this->commandValidator->validate($filePath);
          if (!$result->valid)
          {
            continue;
          }

          $commands[] = [
            'name' => $name,
            'description' => $result->description() ?? 'No description available',
            'path' => $agentCommandsPath . '/' . $entry,
          ];
        }
      }
    }

    usort($commands, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));

    return $commands;
  }

}
