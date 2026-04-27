<?php

declare(strict_types=1);

namespace Thinkliveid\Crew\Commands\Local;

use Thinkliveid\Crew\Commands\CommandValidator;
use Thinkliveid\Crew\Skills\ValidationResult;

class LocalCommandProvider
{
  protected string $sourcePath;
  protected string $targetPath;
  protected CommandValidator $validator;

  /** @var array<string, ValidationResult> */
  protected array $validationResults = [];

  public function __construct(protected string $basePath, ?string $targetPath = null)
  {
    $this->sourcePath = $this->basePath . '/.ai/commands';
    $this->targetPath = $targetPath ?? ($this->basePath . '/.claude/commands');
    $this->validator = new CommandValidator();
  }

  /**
   * Discover local commands in .ai/commands/ that are valid .md files.
   *
   * @return array<int, string>
   */
  public function discoverCommands(): array
  {
    if (!is_dir($this->sourcePath))
    {
      return [];
    }

    $commands = [];
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

  public function getValidationResult(string $name): ?ValidationResult
  {
    return $this->validationResults[$name] ?? null;
  }

  public function syncCommand(string $name): bool
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
   * @return array<int, string>
   */
  public function syncAll(): array
  {
    $commands = $this->discoverCommands();
    $synced = [];

    foreach ($commands as $name)
    {
      if ($this->syncCommand($name))
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
