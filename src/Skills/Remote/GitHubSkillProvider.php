<?php

declare(strict_types=1);

namespace Thinkliveid\Crew\Skills\Remote;

use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;
use Thinkliveid\Crew\Support\Config;

class GitHubSkillProvider
{
  protected string $defaultBranch = 'main';
  protected string $resolvedPath = '';
  protected ?array $cachedTree = null;

  /** @var array<int, string> */
  protected array $commonSkillPaths = [
    'skills',
    '.ai/skills',
    '.cursor/skills',
    '.claude/skills',
  ];

  public function __construct(protected GitHubRepository $repository, protected Config $config)
  {
  }

  /**
   * @return array<string, RemoteSkill>
   */
  public function discoverSkills(): array
  {
    $tree = $this->fetchRepositoryTree();
    if ($tree === null)
    {
      return [];
    }

    $this->resolvedPath = $this->resolveSkillsPath();
    $directories = $this->findSkillDirectoriesInTree($tree['tree'], $this->resolvedPath);
    if (empty($directories))
    {
      return [];
    }

    $validSkills = $this->validateSkillDirectories($directories, $tree['tree']);

    $result = [];
    foreach ($validSkills as $item)
    {
      $skill = new RemoteSkill(
        name: $item['name'],
        repo: $this->repository->fullName(),
        path: $item['path'],
      );
      $result[$skill->name] = $skill;
    }

    return $result;
  }

  public function downloadSkill(RemoteSkill $skill, string $targetPath): bool
  {
    $tree = $this->fetchRepositoryTree();
    if ($tree === null)
    {
      return false;
    }

    $skillFiles = $this->extractSkillFilesFromTree($tree['tree'], $skill->path);
    if (empty($skillFiles))
    {
      return false;
    }

    if (!$this->ensureDirectoryExists($targetPath))
    {
      return false;
    }

    $files = array_filter($skillFiles, fn(array $item): bool => $item['type'] === 'blob');
    $directories = array_filter($skillFiles, fn(array $item): bool => $item['type'] === 'tree');

    foreach ($directories as $dir)
    {
      $relativePath = $this->getRelativePath($dir['path'], $skill->path);
      $localPath = $targetPath . '/' . $relativePath;

      if (!$this->ensureDirectoryExists($localPath))
      {
        return false;
      }
    }

    return $this->downloadFiles($files, $targetPath, $skill->path);
  }

  /**
   * @throws RuntimeException
   */
  protected function fetchRepositoryTree(): ?array
  {
    if ($this->cachedTree !== null)
    {
      return $this->cachedTree;
    }

    $url = sprintf(
      'https://api.github.com/repos/%s/%s/git/trees/%s?recursive=1',
      $this->repository->owner,
      $this->repository->repo,
      $this->defaultBranch
    );

    try
    {
      $response = $this->client()->get($url);
      $tree = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);

      if (!is_array($tree) || !isset($tree['tree']))
      {
        throw new RuntimeException('Invalid response structure from GitHub API');
      }

      $this->cachedTree = $tree;
      return $tree;
    }
    catch (GuzzleException $e)
    {
      $this->handleHttpError($e);
    }
  }

  protected function resolveSkillsPath(): string
  {
    if ($this->repository->path !== '')
    {
      return $this->repository->path;
    }

    $tree = $this->fetchRepositoryTree();
    if ($tree === null)
    {
      return '';
    }

    $treeItems = $tree['tree'];

    $rootDirs = array_column(
      array_filter($treeItems, fn(array $item): bool => $item['type'] === 'tree' && !str_contains((string)$item['path'], '/')),
      'path'
    );

    if ($this->hasValidSkillsAtPath($treeItems, '', $rootDirs))
    {
      return '';
    }

    foreach ($this->commonSkillPaths as $commonPath)
    {
      $topLevel = explode('/', $commonPath)[0];
      if (!in_array($topLevel, $rootDirs, true))
      {
        continue;
      }

      $pathExists = false;
      foreach ($treeItems as $item)
      {
        if ($item['path'] === $commonPath && $item['type'] === 'tree')
        {
          $pathExists = true;
          break;
        }
      }
      if (!$pathExists)
      {
        continue;
      }

      $dirsAtPath = array_map(
        fn(array $item) => basename((string)$item['path']),
        array_filter($treeItems, fn(array $item) => $this->isDirectChildOf($item, $commonPath, 'tree'))
      );

      if ($this->hasValidSkillsAtPath($treeItems, $commonPath, array_values($dirsAtPath)))
      {
        return $commonPath;
      }
    }

    return '';
  }

  protected function hasValidSkillsAtPath(array $treeItems, string $basePath, array $dirNames): bool
  {
    $prefix = $basePath === '' ? '' : $basePath . '/';
    foreach ($dirNames as $dirName)
    {
      $skillMdPath = $prefix . $dirName . '/SKILL.md';
      foreach ($treeItems as $item)
      {
        if ($item['path'] === $skillMdPath && $item['type'] === 'blob')
        {
          return true;
        }
      }
    }
    return false;
  }

  protected function findSkillDirectoriesInTree(array $tree, string $basePath): array
  {
    $result = [];
    foreach ($tree as $item)
    {
      if ($this->isDirectChildOf($item, $basePath, 'tree'))
      {
        $result[] = [
          'name' => basename((string)$item['path']),
          'path' => $item['path'],
          'type' => 'dir',
        ];
      }
    }
    return array_values($result);
  }

  protected function isDirectChildOf(array $item, string $basePath, string $type): bool
  {
    if ($item['type'] !== $type)
    {
      return false;
    }
    $path = (string)$item['path'];
    if ($basePath === '')
    {
      return !str_contains($path, '/');
    }
    $expectedPrefix = $basePath . '/';
    if (!str_starts_with($path, $expectedPrefix))
    {
      return false;
    }
    return !str_contains(substr($path, strlen($expectedPrefix)), '/');
  }

  protected function validateSkillDirectories(array $directories, array $tree): array
  {
    return array_values(array_filter($directories, function (array $dir) use ($tree)
    {
      $skillMdPath = $dir['path'] . '/SKILL.md';
      foreach ($tree as $item)
      {
        if ($item['path'] === $skillMdPath && $item['type'] === 'blob')
        {
          return true;
        }
      }
      return false;
    }));
  }

  protected function extractSkillFilesFromTree(array $tree, string $skillPath): array
  {
    $prefix = $skillPath . '/';
    return array_values(array_filter($tree, fn(array $item) => str_starts_with((string)$item['path'], $prefix)));
  }

  protected function downloadFiles(array $files, string $targetPath, string $basePath): bool
  {
    $client = $this->client();
    $requests = function ($files)
    {
      foreach ($files as $item)
      {
        yield $item['path'] => new Request('GET', $this->buildRawFileUrl($item['path']));
      }
    };

    $allDownloaded = true;
    $pool = new Pool($client, $requests($files), [
      'concurrency' => 5,
      'fulfilled' => function ($response, $path) use ($targetPath, $basePath)
      {
        $localPath = $targetPath . '/' . $this->getRelativePath($path, $basePath);
        $this->ensureDirectoryExists(dirname($localPath));
        file_put_contents($localPath, $response->getBody()->getContents());
      },
      'rejected' => function ($reason) use (&$allDownloaded)
      {
        $allDownloaded = false;
      },
    ]);

    $pool->promise()->wait();
    return $allDownloaded;
  }

  protected function buildRawFileUrl(string $path): string
  {
    return sprintf('https://raw.githubusercontent.com/%s/%s/%s/%s',
      $this->repository->owner, $this->repository->repo, $this->defaultBranch, ltrim($path, '/'));
  }

  protected function getRelativePath(string $fullPath, string $basePath): string
  {
    return str_starts_with($fullPath, $basePath . '/') ? substr($fullPath, strlen($basePath . '/')) : basename($fullPath);
  }

  protected function ensureDirectoryExists(string $path): bool
  {
    return is_dir($path) || mkdir($path, 0755, true) || is_dir($path);
  }

  protected function client(): Client
  {
    $headers = [
      'Accept' => 'application/vnd.github.v3+json',
      'User-Agent' => 'CRM-Boost-CLI',
    ];

    // Mengambil token dari class Config (boost.json)
    $token = $this->config->all()['github_token'] ?? getenv('GITHUB_TOKEN');
    if ($token)
    {
      $headers['Authorization'] = "Bearer {$token}";
    }

    return new Client(['headers' => $headers, 'timeout' => 30]);
  }

  protected function handleHttpError(GuzzleException $e): void
  {
    if (method_exists($e, 'getResponse') && $e->getResponse())
    {
      $res = $e->getResponse();
      if ($res->getStatusCode() === 403 && $res->getHeaderLine('X-RateLimit-Remaining') === '0')
      {
        $reset = $res->getHeaderLine('X-RateLimit-Reset');
        $time = $reset ? date('Y-m-d H:i:s', (int)$reset) : 'unknown';
        throw new RuntimeException("GitHub Rate Limit Exceeded. Resets at: {$time}");
      }
    }
    throw new RuntimeException("GitHub API Error: " . $e->getMessage());
  }
}
