<?php

declare(strict_types=1);

namespace Thinkliveid\Crew\Skills\Local;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Thinkliveid\Crew\Skills\SkillValidator;
use Thinkliveid\Crew\Skills\ValidationResult;

class LocalSkillProvider
{
  protected string $sourcePath;
  protected string $targetPath;
  protected SkillValidator $validator;

  /** @var array<string, ValidationResult> */
  protected array $validationResults = [];

  public function __construct(protected string $basePath, ?string $targetPath = null)
  {
    $this->sourcePath = $this->basePath . '/.ai/skills';
    $this->targetPath = $targetPath ?? ($this->basePath . '/.claude/skills');
    $this->validator = new SkillValidator();
  }

  /**
   * Discover local skills in .ai/skills/ that contain SKILL.md
   *
   * @return array<int, string>
   */
  public function discoverSkills(): array
  {
    if (!is_dir($this->sourcePath))
    {
      return [];
    }

    $skills = [];
    $entries = scandir($this->sourcePath);
    if ($entries === false)
    {
      return [];
    }

    foreach ($entries as $entry)
    {
      if ($entry === '.' || $entry === '..')
      {
        continue;
      }

      $skillDir = $this->sourcePath . '/' . $entry;
      if (!is_dir($skillDir))
      {
        continue;
      }

      if (!file_exists($skillDir . '/SKILL.md'))
      {
        continue;
      }

      $result = $this->validator->validate($skillDir);
      $this->validationResults[$entry] = $result;

      if ($result->valid)
      {
        $skills[] = $entry;
      }
    }

    sort($skills);

    return $skills;
  }

  /**
   * Get validation results for skills that failed validation.
   *
   * @return array<string, ValidationResult>
   */
  public function getInvalidSkills(): array
  {
    return array_filter(
      $this->validationResults,
      static fn(ValidationResult $result): bool => !$result->valid
    );
  }

  /**
   * Get the validation result for a specific skill.
   */
  public function getValidationResult(string $name): ?ValidationResult
  {
    return $this->validationResults[$name] ?? null;
  }

  /**
   * Sync a single skill directory from .ai/skills/ to .claude/skills/
   */
  public function syncSkill(string $name): bool
  {
    $source = $this->sourcePath . '/' . $name;
    $target = $this->targetPath . '/' . $name;

    if (!is_dir($source))
    {
      return false;
    }

    return $this->recursiveCopy($source, $target);
  }

  /**
   * Sync all discovered skills
   *
   * @return array<int, string> List of synced skill names
   */
  public function syncAll(): array
  {
    $skills = $this->discoverSkills();
    $synced = [];

    foreach ($skills as $name)
    {
      if ($this->syncSkill($name))
      {
        $synced[] = $name;
      }
    }

    return $synced;
  }

  public function hasSource(): bool
  {
    return is_dir($this->sourcePath);
  }

  protected function recursiveCopy(string $source, string $target): bool
  {
    if (!is_dir($target) && !mkdir($target, 0755, true) && !is_dir($target))
    {
      return false;
    }

    $iterator = new RecursiveIteratorIterator(
      new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
      RecursiveIteratorIterator::SELF_FIRST
    );

    /** @var SplFileInfo $item */
    foreach ($iterator as $item)
    {
      $relativePath = substr($item->getPathname(), strlen($source) + 1);
      $targetItem = $target . '/' . $relativePath;

      if ($item->isDir())
      {
        if (!is_dir($targetItem) && !mkdir($targetItem, 0755, true) && !is_dir($targetItem))
        {
          return false;
        }
      }
      else
      {
        if (!copy($item->getPathname(), $targetItem))
        {
          return false;
        }
      }
    }

    return true;
  }
}
