<?php

declare(strict_types=1);

namespace Thinkliveid\Crew\SubAgents\Local;

use Thinkliveid\Crew\Skills\ValidationResult;
use Thinkliveid\Crew\SubAgents\SubAgentValidator;

class LocalSubAgentProvider
{
  protected string $sourcePath;
  protected string $targetPath;
  protected SubAgentValidator $validator;

  /** @var array<string, ValidationResult> */
  protected array $validationResults = [];

  public function __construct(protected string $basePath, ?string $targetPath = null)
  {
    $this->sourcePath = $this->basePath . '/.ai/agents';
    $this->targetPath = $targetPath ?? ($this->basePath . '/.claude/agents');
    $this->validator = new SubAgentValidator();
  }

  /**
   * Discover local sub-agents in .ai/agents/ that are valid .md files.
   *
   * @return array<int, string>
   */
  public function discoverSubAgents(): array
  {
    if (!is_dir($this->sourcePath))
    {
      return [];
    }

    $agents = [];
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

      $filePath = $this->sourcePath . '/' . $entry;
      if (!is_file($filePath) || !str_ends_with($entry, '.md'))
      {
        continue;
      }

      $result = $this->validator->validate($filePath);
      $name = pathinfo($entry, PATHINFO_FILENAME);
      $this->validationResults[$name] = $result;

      if ($result->valid)
      {
        $agents[] = $name;
      }
    }

    sort($agents);

    return $agents;
  }

  /**
   * Get validation results for sub-agents that failed validation.
   *
   * @return array<string, ValidationResult>
   */
  public function getInvalidSubAgents(): array
  {
    return array_filter(
      $this->validationResults,
      static fn(ValidationResult $result): bool => !$result->valid
    );
  }

  /**
   * Get the validation result for a specific sub-agent.
   */
  public function getValidationResult(string $name): ?ValidationResult
  {
    return $this->validationResults[$name] ?? null;
  }

  /**
   * Sync a single sub-agent .md file from .ai/agents/ to target path.
   */
  public function syncSubAgent(string $name): bool
  {
    $source = $this->sourcePath . '/' . $name . '.md';
    $target = $this->targetPath . '/' . $name . '.md';

    if (!is_file($source))
    {
      return false;
    }

    if (!is_dir($this->targetPath) && !mkdir($this->targetPath, 0755, true) && !is_dir($this->targetPath))
    {
      return false;
    }

    return copy($source, $target);
  }

  /**
   * Sync all discovered sub-agents.
   *
   * @return array<int, string> List of synced sub-agent names
   */
  public function syncAll(): array
  {
    $agents = $this->discoverSubAgents();
    $synced = [];

    foreach ($agents as $name)
    {
      if ($this->syncSubAgent($name))
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
}
