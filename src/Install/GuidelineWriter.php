<?php

declare(strict_types=1);

namespace Thinkliveid\Crew\Install;

use Thinkliveid\Crew\Contracts\SupportsGuidelines;
use Thinkliveid\Crew\Install\Agents\Agent;
use Thinkliveid\Crew\Skills\SkillValidator;

class GuidelineWriter
{
  private const string TAG_OPEN = '<crew-guidelines>';
  private const string TAG_CLOSE = '</crew-guidelines>';

  protected SkillValidator $validator;

  public function __construct(protected string $basePath)
  {
    $this->validator = new SkillValidator();
  }

  /**
   * Write a managed crew-guidelines block to an agent's guideline file.
   *
   * @param Agent&SupportsGuidelines $agent
   * @param array<int, array{name: string, description: string, path: string}> $skills
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
   * Build the managed block content.
   *
   * @param array<int, array{name: string, description: string, path: string}> $skills
   */
  protected function buildBlock(array $skills): string
  {
    $lines = [];
    $lines[] = self::TAG_OPEN;
    $lines[] = '## Skills';
    $lines[] = '';
    $lines[] = 'This project has the following skills installed:';
    $lines[] = '';

    foreach ($skills as $skill)
    {
      $lines[] = sprintf('- `%s` — %s (path: %s)', $skill['name'], $skill['description'], $skill['path']);
    }

    $lines[] = '';
    $lines[] = self::TAG_CLOSE;

    return implode("\n", $lines);
  }

  /**
   * Merge the managed block into the existing file content.
   */
  protected function mergeContent(string $existing, string $block, bool $frontmatter): string
  {
    // Jika sudah ada crew-guidelines block, replace in-place
    if (str_contains($existing, self::TAG_OPEN) && str_contains($existing, self::TAG_CLOSE))
    {
      $pattern = '/' . preg_quote(self::TAG_OPEN, '/') . '.*?' . preg_quote(self::TAG_CLOSE, '/') . '/s';

      return preg_replace($pattern, $block, $existing);
    }

    // Jika file ada isi tapi belum ada block, append dengan separator
    $trimmed = trim($existing);

    if ($trimmed !== '')
    {
      return $existing . "\n\n===\n\n" . $block . "\n";
    }

    // Jika file kosong/baru, tulis block saja (dengan frontmatter jika perlu)
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

    // Juga kumpulkan skills yang ada langsung di agent skill directory
    // tapi tidak ada di .ai/skills/ (misalnya dari GitHub download langsung)
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

    usort($skills, fn(array $a, array $b): int => strcmp($a['name'], $b['name']));

    return $skills;
  }

}
