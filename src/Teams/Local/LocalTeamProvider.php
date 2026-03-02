<?php

declare(strict_types=1);

namespace Thinkliveid\Crew\Teams\Local;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Thinkliveid\Crew\Skills\ValidationResult;
use Thinkliveid\Crew\Teams\TeamValidator;

class LocalTeamProvider
{
  protected string $sourcePath;
  protected string $targetPath;
  protected TeamValidator $validator;

  /** @var array<string, ValidationResult> */
  protected array $validationResults = [];

  public function __construct(protected string $basePath, ?string $targetPath = null)
  {
    $this->sourcePath = $this->basePath . '/.ai/teams';
    $this->targetPath = $targetPath ?? ($this->basePath . '/.claude/teams');
    $this->validator = new TeamValidator();
  }

  /**
   * Discover local teams in .ai/teams/ that contain TEAM.md.
   *
   * @return array<int, string>
   */
  public function discoverTeams(): array
  {
    if (!is_dir($this->sourcePath))
    {
      return [];
    }

    $teams = [];
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

      $teamDir = $this->sourcePath . '/' . $entry;
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
   * Get the validation result for a specific team.
   */
  public function getValidationResult(string $name): ?ValidationResult
  {
    return $this->validationResults[$name] ?? null;
  }

  /**
   * Sync a single team directory from .ai/teams/ to target path.
   */
  public function syncTeam(string $name): bool
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
   * Sync all discovered teams.
   *
   * @return array<int, string> List of synced team names
   */
  public function syncAll(): array
  {
    $teams = $this->discoverTeams();
    $synced = [];

    foreach ($teams as $name)
    {
      if ($this->syncTeam($name))
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
