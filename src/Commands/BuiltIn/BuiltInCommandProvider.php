<?php

declare(strict_types=1);

namespace Thinkliveid\Crew\Commands\BuiltIn;

use Thinkliveid\Crew\Commands\CommandValidator;
use Thinkliveid\Crew\Skills\ValidationResult;

class BuiltInCommandProvider
{
  protected CommandValidator $validator;

  /** @var array<string, ValidationResult> */
  protected array $validationResults = [];

  public function __construct(protected string $basePath)
  {
    $this->validator = new CommandValidator();
  }

  public function getPackageCommandsPath(): string
  {
    return dirname(__DIR__, 3) . '/resources/commands';
  }

  public function getProjectCommandsPath(): string
  {
    return $this->basePath . '/.ai/commands';
  }

  /**
   * @return array<int, string>
   */
  public function discoverCommands(): array
  {
    $path = $this->getPackageCommandsPath();
    if (!is_dir($path))
    {
      return [];
    }

    $entries = scandir($path);
    if ($entries === false)
    {
      return [];
    }

    $commands = [];
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
        $commands[] = $name;
      }
    }

    sort($commands);

    return $commands;
  }

  /**
   * @return array<string, ValidationResult>
   */
  public function getInvalidCommands(): array
  {
    return array_filter(
      $this->validationResults,
      static fn(ValidationResult $result): bool => !$result->valid
    );
  }

  /**
   * @return array<int, string>
   */
  public function publishAll(): array
  {
    $commands = $this->discoverCommands();
    if (empty($commands))
    {
      return [];
    }

    $projectPath = $this->getProjectCommandsPath();
    $packagePath = $this->getPackageCommandsPath();
    $published = [];

    if (!is_dir($projectPath) && !mkdir($projectPath, 0755, true) && !is_dir($projectPath))
    {
      return [];
    }

    foreach ($commands as $name)
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
