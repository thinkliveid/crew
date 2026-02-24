<?php

declare(strict_types=1);

namespace Thinkliveid\Crew\Install\Detection;

use InvalidArgumentException;
use Thinkliveid\Crew\Install\Contracts\DetectionStrategy;

class DetectionStrategyFactory
{
  private const string TYPE_DIRECTORY = 'directory';
  private const string TYPE_COMMAND = 'command';
  private const string TYPE_FILE = 'file';

  /**
   * Kita buat container menjadi opsional.
   * Jika tidak ada container, kita instansiasi manual.
   */
  public function __construct(private readonly mixed $container = null)
  {
    //
  }

  public function make(string|array $type, array $config = []): DetectionStrategy
  {
    if (is_array($type))
    {
      return new CompositeDetectionStrategy(
        array_map(fn(string|array $singleType): DetectionStrategy => $this->make($singleType, $config), $type)
      );
    }

    return match ($type)
    {
      self::TYPE_DIRECTORY => $this->resolve(DirectoryDetectionStrategy::class),
      self::TYPE_COMMAND   => $this->resolve(CommandDetectionStrategy::class),
      self::TYPE_FILE      => $this->resolve(FileDetectionStrategy::class),
      default              => throw new InvalidArgumentException("Unknown detection type: {$type}"),
    };
  }

  /**
   * Helper untuk handle resolusi class baik lewat container atau manual.
   */
  protected function resolve(string $className): DetectionStrategy
  {
    if ($this->container && method_exists($this->container, 'get'))
    {
      return $this->container->get($className);
    }

    if ($this->container && method_exists($this->container, 'make'))
    {
      return $this->container->make($className);
    }

    return new $className();
  }

  public function makeFromConfig(array $config): DetectionStrategy
  {
    $type = $this->inferTypeFromConfig($config);

    return $this->make($type, $config);
  }

  protected function inferTypeFromConfig(array $config): string|array
  {
    $typeMap = [
      'files' => self::TYPE_FILE,
      'paths' => self::TYPE_DIRECTORY,
      'command' => self::TYPE_COMMAND,
    ];

    $types = array_values(array_filter($typeMap, fn($value, $key) => array_key_exists($key, $config), ARRAY_FILTER_USE_BOTH));

    if (empty($types))
    {
      $allowedKeys = implode(', ', array_keys($typeMap));
      throw new InvalidArgumentException(
        "Cannot infer detection type from config keys. Expected one of: {$allowedKeys}"
      );
    }

    return count($types) > 1 ? $types : reset($types);
  }
}