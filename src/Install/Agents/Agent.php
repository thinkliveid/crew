<?php

declare(strict_types=1);

namespace Thinkliveid\Crew\Install\Agents;

use Symfony\Component\Process\Process;
use Thinkliveid\Crew\Enums\McpInstallationStrategy;
use Thinkliveid\Crew\Enums\Platform;
use Thinkliveid\Crew\Install\Detection\DetectionStrategyFactory;
use Thinkliveid\Crew\Install\Mcp\FileWriter;
use Thinkliveid\Crew\Install\Mcp\TomlFileWriter;

abstract class Agent
{
  public function __construct(protected readonly DetectionStrategyFactory $strategyFactory)
  {
  }

  abstract public function name(): string;

  abstract public function displayName(): string;

  public function useAbsolutePathForMcp(): bool
  {
    return false;
  }

  public function getPhpPath(bool $forceAbsolutePath = false): string
  {
    if ($this->useAbsolutePathForMcp() || $forceAbsolutePath)
    {
      return PHP_BINARY;
    }

    return 'php';
  }

  public function getArtisanPath(bool $forceAbsolutePath = false): string
  {
    return ($this->useAbsolutePathForMcp() || $forceAbsolutePath)
      ? getcwd() . '/artisan'
      : 'artisan';
  }

  /**
   * Get the detection configuration for system-wide installation detection.
   *
   * @return array{paths?: string[], command?: string, files?: string[]}
   */
  abstract public function systemDetectionConfig(Platform $platform): array;

  /**
   * Get the detection configuration for project-specific detection.
   *
   * @return array{paths?: string[], files?: string[]}
   */
  abstract public function projectDetectionConfig(): array;

  public function detectOnSystem(Platform $platform): bool
  {
    $config = $this->systemDetectionConfig($platform);
    return $this->strategyFactory->makeFromConfig($config)->detect($config, $platform);
  }

  public function detectInProject(string $basePath): bool
  {
    $config = array_merge($this->projectDetectionConfig(), ['basePath' => $basePath]);
    return $this->strategyFactory->makeFromConfig($config)->detect($config);
  }

  public function mcpInstallationStrategy(): McpInstallationStrategy
  {
    return McpInstallationStrategy::File;
  }

  /**
   * Resolve an agent instance by name from a list of agent classes.
   *
   * @param string $name
   * @param array<class-string<Agent>> $agentClasses
   * @return Agent|null
   */
  public static function fromName(string $name, array $agentClasses = []): ?self
  {
    $factory = new DetectionStrategyFactory();
    foreach ($agentClasses as $class)
    {
      $instance = new $class($factory);
      if ($instance->name() === $name)
      {
        return $instance;
      }
    }

    return null;
  }

  public function shellMcpCommand(): ?string
  {
    return null;
  }

  public function mcpConfigPath(): ?string
  {
    return null;
  }

  public function frontmatter(): bool
  {
    return false;
  }

  public function mcpConfigKey(): string
  {
    return 'mcpServers';
  }

  /** @return array<string, mixed> */
  public function defaultMcpConfig(): array
  {
    return [];
  }

  /**
   * Install MCP server using the appropriate strategy.
   *
   * @param array<int, string> $args
   * @param array<string, string> $env
   */
  public function installMcp(string $key, string $command, array $args = [], array $env = []): bool
  {
    return match ($this->mcpInstallationStrategy())
    {
      McpInstallationStrategy::Shell => $this->installShellMcp($key, $command, $args, $env),
      McpInstallationStrategy::File => $this->installFileMcp($key, $command, $args, $env),
      McpInstallationStrategy::None => false,
    };
  }

  /**
   * Build the HTTP MCP server configuration payload for file-based installation.
   *
   * @return array<string, mixed>
   */
  public function httpMcpServerConfig(string $url): array
  {
    return [
      'type' => 'http',
      'url' => $url,
    ];
  }

  /**
   * Install an HTTP MCP server using the file-based strategy.
   */
  public function installHttpMcp(string $key, string $url): bool
  {
    $path = $this->mcpConfigPath();
    if (!$path)
    {
      return false;
    }

    $writer = str_ends_with($path, '.toml')
      ? new TomlFileWriter($path, $this->defaultMcpConfig())
      : new FileWriter($path, $this->defaultMcpConfig());

    return $writer
      ->configKey($this->mcpConfigKey())
      ->addServerConfig($key, $this->httpMcpServerConfig($url))
      ->save();
  }

  /**
   * Build the MCP server configuration payload for file-based installation.
   *
   * @param array<int, string> $args
   * @param array<string, string> $env
   * @return array<string, mixed>
   */
  public function mcpServerConfig(string $command, array $args = [], array $env = []): array
  {
    return [
      'command' => $command,
      'args' => $args,
      'env' => $env,
    ];
  }

  /**
   * Install MCP server using a shell command strategy.
   *
   * @param array<int, string> $args
   * @param array<string, string> $env
   */
  protected function installShellMcp(string $key, string $command, array $args = [], array $env = []): bool
  {
    $shellCommand = $this->shellMcpCommand();
    if ($shellCommand === null)
    {
      return false;
    }

    $normalized = $this->normalizeCommand($command, $args);
    $envString = '';
    foreach ($env as $envKey => $value)
    {
      $envKey = strtoupper($envKey);
      $envString .= "-e {$envKey}=\"{$value}\" ";
    }

    $command = str_replace([
      '{key}',
      '{command}',
      '{args}',
      '{env}',
    ], [
      $key,
      $normalized['command'],
      implode(' ', array_map(fn(string $arg): string => '"' . $arg . '"', $normalized['args'])),
      trim($envString),
    ], $shellCommand);

    $process = Process::fromShellCommandline($command);
    $process->run();

    if ($process->isSuccessful())
    {
      return true;
    }

    return str_contains($process->getErrorOutput(), 'already exists');
  }

  /**
   * Install MCP server using a file-based configuration strategy.
   *
   * @param array<int, string> $args
   * @param array<string, string> $env
   */
  protected function installFileMcp(string $key, string $command, array $args = [], array $env = []): bool
  {
    $path = $this->mcpConfigPath();
    if (!$path)
    {
      return false;
    }

    $normalized = $this->normalizeCommand($command, $args);
    $writer = str_ends_with($path, '.toml')
      ? new TomlFileWriter($path, $this->defaultMcpConfig())
      : new FileWriter($path, $this->defaultMcpConfig());

    return $writer
      ->configKey($this->mcpConfigKey())
      ->addServerConfig($key, $this->mcpServerConfig($normalized['command'], $normalized['args'], $env))
      ->save();
  }

  /**
   * Normalize command by splitting space-separated commands into command + args.
   *
   * Absolute paths (starting with / on Unix or a drive letter on Windows)
   * are never split, as they may contain spaces (e.g. macOS "Application Support").
   *
   * @param array<int, string> $args
   * @return array{command: string, args: array<int, string>}
   */
  protected function normalizeCommand(string $command, array $args = []): array
  {
    if (str_starts_with($command, '/') || preg_match('#^[a-zA-Z]:[/\\\\]#', $command))
    {
      return [
        'command' => $command,
        'args' => $args,
      ];
    }

    $parts = explode(' ', $command);
    $first = array_shift($parts);

    return [
      'command' => $first,
      'args' => array_values(array_merge($parts, $args)),
    ];
  }

  /**
   * Post-process the generated guidelines' Markdown.
   */
  public function transformGuidelines(string $markdown): string
  {
    return $markdown;
  }
}
