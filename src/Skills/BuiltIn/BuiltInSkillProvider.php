<?php

declare(strict_types=1);

namespace Thinkliveid\Crew\Skills\BuiltIn;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Thinkliveid\Crew\Skills\SkillValidator;
use Thinkliveid\Crew\Skills\ValidationResult;

class BuiltInSkillProvider
{
  protected SkillValidator $validator;

  /** @var array<string, ValidationResult> */
  protected array $validationResults = [];

  public function __construct(protected string $basePath)
  {
    $this->validator = new SkillValidator();
  }

  /**
   * Get the path to the built-in skills bundled with the package.
   */
  public function getPackageSkillsPath(): string
  {
    return dirname(__DIR__, 3) . '/resources/skills';
  }

  /**
   * Get the path to the project's local skills directory.
   */
  public function getProjectSkillsPath(): string
  {
    return $this->basePath . '/.ai/skills';
  }

  /**
   * Discover all built-in skill directories that contain SKILL.md.
   *
   * @return array<int, string>
   */
  public function discoverSkills(): array
  {
    $path = $this->getPackageSkillsPath();

    if (!is_dir($path))
    {
      return [];
    }

    $entries = scandir($path);

    if ($entries === false)
    {
      return [];
    }

    $skills = [];

    foreach ($entries as $entry)
    {
      if ($entry === '.' || $entry === '..')
      {
        continue;
      }

      $skillDir = $path . '/' . $entry;

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
      fn(ValidationResult $result): bool => !$result->valid
    );
  }

  /**
   * Publish all built-in skills to the project's .ai/skills/ directory.
   * Skips skills that already exist in the target to avoid overwriting user modifications.
   *
   * @return array<int, string> List of published skill names
   */
  public function publishAll(): array
  {
    $skills = $this->discoverSkills();

    if (empty($skills))
    {
      return [];
    }

    $projectPath = $this->getProjectSkillsPath();
    $packagePath = $this->getPackageSkillsPath();
    $published = [];

    foreach ($skills as $name)
    {
      $targetDir = $projectPath . '/' . $name;

      // Skip jika skill sudah ada di project
      if (is_dir($targetDir))
      {
        continue;
      }

      $sourceDir = $packagePath . '/' . $name;

      if ($this->recursiveCopy($sourceDir, $targetDir))
      {
        $published[] = $name;
      }
    }

    return $published;
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
