<?php

declare(strict_types=1);

namespace Thinkliveid\Crew\Install\Mcp;

use Thinkliveid\Crew\Support\Arr;

class FileWriter
{
  protected string $configKey = 'mcpServers';

  protected array $serversToAdd = [];

  protected int $defaultIndentation = 8;

  public function __construct(protected string $filePath, protected array $baseConfig = [])
  {
    //
  }

  public function configKey(string $key): self
  {
    $this->configKey = $key;

    return $this;
  }

  /**
   * @param array<string, mixed> $config
   */
  public function addServerConfig(string $key, array $config): self
  {
    $this->serversToAdd[$key] = array_filter(
      $config,
      fn($value): bool => !in_array($value, [[], null, ''], true)
    );

    return $this;
  }

  public function save(): bool
  {
    $this->ensureDirectoryExists();

    if ($this->shouldWriteNew())
    {
      return $this->createNewFile();
    }

    $content = $this->readFile();

    if ($this->isPlainJson($content))
    {
      return $this->updatePlainJsonFile($content);
    }

    return $this->updateJson5File($content);
  }

  protected function updatePlainJsonFile(string $content): bool
  {
    $config = json_decode($content, true);

    if (json_last_error() !== JSON_ERROR_NONE)
    {
      return false;
    }

    $this->addServersToConfig($config);

    return $this->writeJsonConfig($config);
  }

  protected function updateJson5File(string $content): bool
  {
    $configKeyPattern = '/["\']' . preg_quote($this->configKey, '/') . '["\']\\s*:\\s*\\{/';

    if (preg_match($configKeyPattern, $content, $matches, PREG_OFFSET_CAPTURE))
    {
      return $this->injectIntoExistingConfigKey($content, $matches);
    }

    return $this->injectNewConfigKey($content);
  }

  protected function injectIntoExistingConfigKey(string $content, array $matches): bool
  {
    $configKeyStart = $matches[0][1];

    $openBracePos = strpos($content, '{', $configKeyStart);

    if ($openBracePos === false)
    {
      return false;
    }

    $closeBracePos = $this->findMatchingClosingBrace($content, $openBracePos);

    if ($closeBracePos === false)
    {
      return false;
    }

    $serversToAdd = $this->filterExistingServers($content, $openBracePos, $closeBracePos);

    if ($serversToAdd === [])
    {
      return true;
    }

    $indentLength = $this->detectIndentation($content, $closeBracePos);

    $serverJsonParts = [];

    foreach ($serversToAdd as $key => $serverConfig)
    {
      $serverJsonParts[] = $this->generateServerJson($key, $serverConfig, $indentLength);
    }

    $serversJson = implode(',' . "\n", $serverJsonParts);

    $needsComma = $this->needsCommaBeforeClosingBrace($content, $openBracePos, $closeBracePos);

    if (!$needsComma)
    {
      $newContent = substr_replace($content, $serversJson, $closeBracePos, 0);

      return $this->writeFile($newContent);
    }

    $commaPosition = $this->findCommaInsertionPoint($content, $openBracePos, $closeBracePos);

    if ($commaPosition !== -1)
    {
      $newContent = substr_replace($content, ',', $commaPosition, 0);
      $newContent = substr_replace($newContent, $serversJson, $commaPosition + 1, 0);
    }
    else
    {
      $newContent = substr_replace($content, $serversJson, $closeBracePos, 0);
    }

    return $this->writeFile($newContent);
  }

  protected function filterExistingServers(string $content, int $openBracePos, int $closeBracePos): array
  {
    $configContent = substr($content, $openBracePos + 1, $closeBracePos - $openBracePos - 1);
    $filteredServers = [];

    foreach ($this->serversToAdd as $key => $serverConfig)
    {
      if (!$this->serverExistsInContent($configContent, $key))
      {
        $filteredServers[$key] = $serverConfig;
      }
    }

    return $filteredServers;
  }

  protected function serverExistsInContent(string $content, string $serverKey): bool
  {
    $quotedPattern = '/["\']' . preg_quote($serverKey, '/') . '["\']\\s*:/';
    $unquotedPattern = '/(?<=^|\\s|,|{)' . preg_quote($serverKey, '/') . '\\s*:/m';

    return (bool)preg_match($quotedPattern, $content) || (bool)preg_match($unquotedPattern, $content);
  }

  protected function injectNewConfigKey(string $content): bool
  {
    $openBracePos = strpos($content, '{');

    if ($openBracePos === false)
    {
      return false;
    }

    $serverJsonParts = [];

    foreach ($this->serversToAdd as $key => $serverConfig)
    {
      $serverJsonParts[] = $this->generateServerJson($key, $serverConfig);
    }

    $serversJson = implode(',', $serverJsonParts);
    $configKeySection = '"' . $this->configKey . '": {' . $serversJson . '}';

    $needsComma = $this->needsCommaAfterBrace($content, $openBracePos);
    $injection = $configKeySection . ($needsComma ? ',' : '');

    $newContent = substr_replace($content, $injection, $openBracePos + 1, 0);

    return $this->writeFile($newContent);
  }

  protected function generateServerJson(string $key, array $serverConfig, int $baseIndent = 0): string
  {
    $json = json_encode($serverConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    $json = str_replace("\r\n", "\n", $json);

    if (empty($baseIndent))
    {
      return '"' . $key . '": ' . $json;
    }

    $baseIndent = str_repeat(' ', $baseIndent);
    $lines = explode("\n", $json);
    $firstLine = array_shift($lines);
    $indentedLines = [
      "{$baseIndent}\"{$key}\": {$firstLine}",
      ...array_map(fn(string $line): string => $baseIndent . $line, $lines),
    ];

    return "\n" . implode("\n", $indentedLines);
  }

  protected function needsCommaAfterBrace(string $content, int $bracePosition): bool
  {
    $afterBrace = substr($content, $bracePosition + 1);
    $trimmed = preg_replace('/^\s*(?:\/\/.*$)?/m', '', $afterBrace);

    return $trimmed !== '' && $trimmed !== null && !str_starts_with($trimmed, '}');
  }

  protected function findMatchingClosingBrace(string $content, int $openBracePos): int|false
  {
    $braceCount = 1;
    $length = strlen($content);
    $inString = false;
    $escaped = false;

    for ($i = $openBracePos + 1; $i < $length; $i++)
    {
      $char = $content[$i];

      if (!$inString)
      {
        if ($char === '{')
        {
          $braceCount++;
        }
        elseif ($char === '}')
        {
          $braceCount--;

          if ($braceCount === 0)
          {
            return $i;
          }
        }
      }

      if ($char === '"' && !$escaped)
      {
        $inString = !$inString;
      }

      $escaped = ($char === '\\' && !$escaped);
    }

    return false;
  }

  protected function needsCommaBeforeClosingBrace(string $content, int $openBracePos, int $closeBracePos): bool
  {
    $innerContent = substr($content, $openBracePos + 1, $closeBracePos - $openBracePos - 1);

    $trimmed = preg_replace('/\s+|\/\/.*$/m', '', $innerContent);

    if ($trimmed === '' || $trimmed === null || str_ends_with($trimmed, '{'))
    {
      return false;
    }

    return !str_ends_with($trimmed, ',');
  }

  protected function findCommaInsertionPoint(string $content, int $openBracePos, int $closeBracePos): int
  {
    for ($i = $closeBracePos - 1; $i > $openBracePos; $i--)
    {
      $char = $content[$i];

      if (in_array($char, [' ', "\t", "\n", "\r"], true))
      {
        continue;
      }

      if ($i > 0 && $content[$i - 1] === '/' && $char === '/')
      {
        $lineStart = strrpos($content, "\n", $i - strlen($content)) ?: 0;
        $i = $lineStart;

        continue;
      }

      if ($char !== ',')
      {
        return $i + 1;
      }

      return -1;
    }

    return $openBracePos + 1;
  }

  public function detectIndentation(string $content, int $nearPosition): int
  {
    $lines = explode("\n", substr($content, 0, $nearPosition));

    for ($i = count($lines) - 1; $i >= 0; $i--)
    {
      $line = $lines[$i];

      if (preg_match('/^(\s*)"[^"]+"\s*:\s*\{/', $line, $matches))
      {
        return strlen($matches[1]);
      }
    }

    return $this->defaultIndentation;
  }

  protected function isPlainJson(string $content): bool
  {
    if ($this->hasUnquotedComments($content))
    {
      return false;
    }

    if (preg_match('/,\s*[\]}]/', $content))
    {
      return false;
    }

    if (preg_match('/^\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*:/m', $content))
    {
      return false;
    }

    json_decode($content);

    return json_last_error() === JSON_ERROR_NONE;
  }

  protected function hasUnquotedComments(string $content): bool
  {
    $pattern = '/"(\\\\.|[^"\\\\])*"|(\/\/.*)/';

    if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER))
    {
      foreach ($matches as $match)
      {
        if (!empty($match[2]))
        {
          return true;
        }
      }
    }

    return false;
  }

  protected function createNewFile(): bool
  {
    $config = $this->baseConfig;
    $this->addServersToConfig($config);

    return $this->writeJsonConfig($config);
  }

  protected function addServersToConfig(array &$config): void
  {
    foreach ($this->serversToAdd as $key => $serverConfig)
    {
      Arr::set($config, $this->configKey . '.' . $key, $serverConfig);
    }
  }

  protected function writeJsonConfig(array $config): bool
  {
    $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    if ($json)
    {
      $json = str_replace("\r\n", "\n", $json);
    }

    return $json && $this->writeFile($json);
  }

  protected function ensureDirectoryExists(): void
  {
    $dir = dirname($this->filePath);
    if (!is_dir($dir))
    {
      mkdir($dir, 0755, true);
    }
  }

  protected function fileExists(): bool
  {
    return file_exists($this->filePath);
  }

  protected function shouldWriteNew(): bool
  {
    if (!$this->fileExists())
    {
      return true;
    }

    return filesize($this->filePath) < 3;
  }

  protected function readFile(): string
  {
    return file_get_contents($this->filePath);
  }

  protected function writeFile(string $content): bool
  {
    return file_put_contents($this->filePath, $content) !== false;
  }
}
