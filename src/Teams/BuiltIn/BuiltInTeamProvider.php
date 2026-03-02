<?php

declare(strict_types=1);

namespace Thinkliveid\Crew\Teams\BuiltIn;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Thinkliveid\Crew\Skills\ValidationResult;
use Thinkliveid\Crew\Teams\TeamValidator;

class BuiltInTeamProvider
{
  protected TeamValidator $validator;

  /** @var array<string, ValidationResult> */
  protected array $validationResults = [];

  public function __construct(protected string $basePath)
  {
    $this->validator = new TeamValidator();
  }

  /**
   * Get the path to the built-in teams bundled with the package.
   */
  public function getPackageTeamsPath(): string
  {
    return dirname(__DIR__, 3) . '/resources/teams';
  }

  /**
   * Get the path to the project's local teams directory.
   */
  public function getProjectTeamsPath(): string
  {
    return $this->basePath . '/.ai/teams';
  }

  /**
   * Discover all built-in team directories that contain TEAM.md.
   *
   * @return array<int, string>
   */
  public function discoverTeams(): array
  {
    $path = $this->getPackageTeamsPath();
    if (!is_dir($path))
    {
      return [];
    }

    $entries = scandir($path);
    if ($entries === false)
    {
      return [];
    }

    $teams = [];
    foreach ($entries as $entry)
    {
      if ($entry === '.' || $entry === '..')
      {
        continue;
      }

      $teamDir = $path . '/' . $entry;
      if (!is_dir($teamDir))
      {
        continue;
      }

      if (!file_exists($teamDir . '/TEAM.md'))
      {
        continue;
      }

      $result = $this->validator->validate($teamDir);
      $this->validationResults[$entry] = $result;
      if ($result->valid)
      {
        $teams[] = $entry;
      }
    }

    sort($teams);

    return $teams;
  }

  /**
   * Get validation results for teams that failed validation.
   *
   * @return array<string, ValidationResult>
   */
  public function getInvalidTeams(): array
  {
    return array_filter(
      $this->validationResults,
      static fn(ValidationResult $result): bool => !$result->valid
    );
  }

  /**
   * Publish all built-in teams to the project's .ai/teams/ directory.
   * Skips teams that already exist in the target to avoid overwriting user modifications.
   *
   * @return array<int, string> List of published team names
   */
  public function publishAll(): array
  {
    $teams = $this->discoverTeams();
    if (empty($teams))
    {
      return [];
    }

    $projectPath = $this->getProjectTeamsPath();
    $packagePath = $this->getPackageTeamsPath();
    $published = [];

    foreach ($teams as $name)
    {
      $targetDir = $projectPath . '/' . $name;
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
