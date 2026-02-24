<?php

declare(strict_types=1);

namespace Thinkliveid\Crew\Support;

class Config
{
  protected const string FILE = 'crew.json';
  protected string $basePath;

  public function __construct(?string $basePath = null)
  {
    $this->basePath = $basePath ?? dirname(__DIR__, 2);
  }

  public function getGuidelines(): bool
  {
    return (bool)$this->get('guidelines', false);
  }

  public function setGuidelines(bool $enabled): void
  {
    $this->set('guidelines', $enabled);
  }

  /** @return array<int, string> */
  public function getSkills(): array
  {
    return $this->get('skills', []);
  }

  /** @param array<int, string> $skills */
  public function setSkills(array $skills): void
  {
    $this->set('skills', $skills);
  }

  public function hasSkills(): bool
  {
    return $this->getSkills() !== [];
  }

  public function getMcp(): bool
  {
    return (bool)$this->get('mcp', false);
  }

  public function setMcp(bool $enabled): void
  {
    $this->set('mcp', $enabled);
  }

  public function setHerdMcp(bool $installed): void
  {
    $this->set('herd_mcp', $installed);
  }

  public function getHerdMcp(): bool
  {
    return (bool)$this->get('herd_mcp', false);
  }

  public function setNightwatchMcp(bool $installed): void
  {
    $this->set('nightwatch_mcp', $installed);
  }

  public function getNightwatchMcp(): bool
  {
    return (bool)$this->get('nightwatch_mcp', false);
  }

  /** @return array<int, string> */
  public function getPackages(): array
  {
    return $this->get('packages', []);
  }

  /** @param array<int, string> $packages */
  public function setPackages(array $packages): void
  {
    $this->set('packages', $packages);
  }

  /** @param array<int, string> $agents */
  public function setAgents(array $agents): void
  {
    $this->set('agents', $agents);
  }

  /** @return array<int, string> */
  public function getAgents(): array
  {
    return $this->get('agents', []);
  }

  public function setSail(bool $useSail): void
  {
    $this->set('sail', $useSail);
  }

  public function getSail(): bool
  {
    return (bool)$this->get('sail', false);
  }

  public function isValid(): bool
  {
    $path = $this->getFilePath();
    if (!file_exists($path))
    {
      return false;
    }

    $content = file_get_contents($path);
    if (empty($content))
    {
      return false;
    }

    try
    {
      json_decode($content, true, 512, JSON_THROW_ON_ERROR);
      return true;
    }
    catch (\Throwable $ex)
    {
      return false;
    }
  }

  public function flush(): void
  {
    $path = $this->getFilePath();
    if (file_exists($path))
    {
      unlink($path);
    }
  }

  protected function getFilePath(): string
  {
    return $this->basePath . DIRECTORY_SEPARATOR . self::FILE;
  }

  protected function get(string $key, mixed $default = null): mixed
  {
    return Arr::get($this->all(), $key, $default);
  }

  protected function set(string $key, mixed $value): void
  {
    $config = array_filter($this->all(), static fn($v): bool => $v !== null && $v !== []);
    Arr::set($config, $key, $value);

    ksort($config);

    $path = $this->getFilePath();
    $json = json_encode($config, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    file_put_contents($path, $json . PHP_EOL);
  }

  public function all(): array
  {
    $path = $this->getFilePath();

    if (!file_exists($path))
    {
      return [];
    }

    $content = file_get_contents($path);
    if ($content === false)
    {
      return [];
    }

    $config = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    return (json_last_error() === JSON_ERROR_NONE) ? ($config ?? []) : [];
  }
}