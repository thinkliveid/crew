<?php

declare(strict_types=1);

namespace Thinkliveid\Crew\SubAgents\BuiltIn;

use Thinkliveid\Crew\Skills\ValidationResult;
use Thinkliveid\Crew\SubAgents\SubAgentValidator;

class BuiltInSubAgentProvider
{
  protected SubAgentValidator $validator;

  /** @var array<string, ValidationResult> */
  protected array $validationResults = [];

  public function __construct(protected string $basePath)
  {
    $this->validator = new SubAgentValidator();
  }

  /**
   * Get the path to the built-in sub-agents bundled with the package.
   */
  public function getPackageAgentsPath(): string
  {
    return dirname(__DIR__, 3) . '/resources/agents';
  }

  /**
   * Get the path to the project's local agents directory.
   */
  public function getProjectAgentsPath(): string
  {
    return $this->basePath . '/.ai/agents';
  }

  /**
   * Discover all built-in sub-agent .md files.
   *
   * @return array<int, string>
   */
  public function discoverSubAgents(): array
  {
    $path = $this->getPackageAgentsPath();
    if (!is_dir($path))
    {
      return [];
    }

    $entries = scandir($path);
    if ($entries === false)
    {
      return [];
    }

    $agents = [];
    foreach ($entries as $entry)
    {
      if ($entry === '.' || $entry === '..')
      {
        continue;
      }

      $filePath = $path . '/' . $entry;
      if (!is_file($filePath) || !str_ends_with($entry, '.md'))
      {
        continue;
      }

      $name = pathinfo($entry, PATHINFO_FILENAME);
      $result = $this->validator->validate($filePath);
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
   * Publish all built-in sub-agents to the project's .ai/agents/ directory.
   * Skips sub-agents that already exist in the target to avoid overwriting user modifications.
   *
   * @return array<int, string> List of published sub-agent names
   */
  public function publishAll(): array
  {
    $agents = $this->discoverSubAgents();
    if (empty($agents))
    {
      return [];
    }

    $projectPath = $this->getProjectAgentsPath();
    $packagePath = $this->getPackageAgentsPath();
    $published = [];

    if (!is_dir($projectPath) && !mkdir($projectPath, 0755, true) && !is_dir($projectPath))
    {
      return [];
    }

    foreach ($agents as $name)
    {
      $targetFile = $projectPath . '/' . $name . '.md';
      if (file_exists($targetFile))
      {
        continue;
      }

      $sourceFile = $packagePath . '/' . $name . '.md';
      if (copy($sourceFile, $targetFile))
      {
        $published[] = $name;
      }
    }

    return $published;
  }
}
