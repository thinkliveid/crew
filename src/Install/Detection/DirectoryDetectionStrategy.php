<?php

declare(strict_types=1);

namespace Thinkliveid\Crew\Install\Detection;

use Thinkliveid\Crew\Enums\Platform;
use Thinkliveid\Crew\Install\Contracts\DetectionStrategy;

class DirectoryDetectionStrategy implements DetectionStrategy
{
  public function detect(array $config, ?Platform $platform = null): bool
  {
    if (!isset($config['paths']))
    {
      return false;
    }

    $paths = (array)$config['paths'];
    $basePath = $config['basePath'] ?? '';
    foreach ($paths as $path)
    {
      $expandedPath = $this->expandPath($path, $platform);
      if ($basePath !== '' && !$this->isAbsolutePath($expandedPath))
      {
        $expandedPath = rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($expandedPath, DIRECTORY_SEPARATOR);
      }

      if (str_contains($expandedPath, '*'))
      {
        $matches = glob($expandedPath, GLOB_ONLYDIR | GLOB_NOSORT);

        if ($matches !== false && !empty($matches))
        {
          return true;
        }
      }
      elseif (is_dir($expandedPath))
      {
        return true;
      }
    }

    return false;
  }

  protected function expandPath(string $path, ?Platform $platform = null): string
  {
    $platform = $platform ?? $this->detectPlatform();
    if ($platform === Platform::Windows)
    {
      return preg_replace_callback('/%([^%]+)%/', static function (array $matches)
      {
        $env = getenv($matches[1]);
        return $env !== false ? $env : $matches[0];
      }, $path);
    }

    if (str_starts_with($path, '~'))
    {
      $home = getenv('HOME') ?: getenv('USERPROFILE');
      if ($home)
      {
        return $home . substr($path, 1);
      }
    }

    return $path;
  }

  protected function isAbsolutePath(string $path): bool
  {
    if ($path === '')
    {
      return false;
    }

    return str_starts_with($path, '/') ||
      str_starts_with($path, '\\') ||
      (strlen($path) > 1 && $path[1] === ':'); // Format C:\
  }

  protected function detectPlatform(): Platform
  {
    return str_starts_with(strtoupper(PHP_OS), 'WIN')
      ? Platform::Windows
      : Platform::Linux;
  }
}