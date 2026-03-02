<?php

declare(strict_types=1);

namespace Thinkliveid\Crew\Teams\Remote;

use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Exception\GuzzleException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Thinkliveid\Crew\Skills\Remote\GitHubRepository;
use Thinkliveid\Crew\Support\Config;

class GitHubTeamProvider
{
  protected string $defaultBranch = 'main';
  protected string $resolvedPath = '';
  protected ?array $cachedTree = null;

  /** @var array<int, string> */
  protected array $commonTeamPaths = [
    'teams',
    '.ai/teams',
    '.claude/teams',
  ];

  public function __construct(protected GitHubRepository $repository, protected Config $config)
  {
  }

  /**
   * @return array<string, RemoteTeam>
   */
  public function discoverTeams(): array
  {
    $tree = $this->fetchRepositoryTree();
    if ($tree === null)
    {
      return [];
    }

    $this->resolvedPath = $this->resolveTeamsPath();
    $directories = $this->findTeamDirectoriesInTree($tree['tree'], $this->resolvedPath);
    if (empty($directories))
    {
      return [];
    }

    $validTeams = $this->validateTeamDirectories($directories, $tree['tree']);

    $result = [];
    foreach ($validTeams as $item)
    {
      $team = new RemoteTeam(
        name: $item['name'],
        repo: $this->repository->fullName(),
        path: $item['path'],
      );
      $result[$team->name] = $team;
    }

    return $result;
  }

  public function downloadTeam(RemoteTeam $team, string $targetPath): bool
  {
    $tree = $this->fetchRepositoryTree();
    if ($tree === null)
    {
      return false;
    }

    $teamFiles = $this->extractTeamFilesFromTree($tree['tree'], $team->path);
    if (empty($teamFiles))
    {
      return false;
    }

    if (!$this->ensureDirectoryExists($targetPath))
    {
      return false;
    }

    $files = array_filter($teamFiles, fn(array $item): bool => $item['type'] === 'blob');
    $directories = array_filter($teamFiles, fn(array $item): bool => $item['type'] === 'tree');

    foreach ($directories as $dir)
    {
      $relativePath = $this->getRelativePath($dir['path'], $team->path);
      $localPath = $targetPath . '/' . $relativePath;

      if (!$this->ensureDirectoryExists($localPath))
      {
        return false;
      }
    }

    return $this->downloadFiles($files, $targetPath, $team->path);
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

  protected function resolveTeamsPath(): string
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

    if ($this->hasValidTeamsAtPath($treeItems, '', $rootDirs))
    {
      return '';
    }

    foreach ($this->commonTeamPaths as $commonPath)
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

      if ($this->hasValidTeamsAtPath($treeItems, $commonPath, array_values($dirsAtPath)))
      {
        return $commonPath;
      }
    }

    return '';
  }

  protected function hasValidTeamsAtPath(array $treeItems, string $basePath, array $dirNames): bool
  {
    $prefix = $basePath === '' ? '' : $basePath . '/';
    foreach ($dirNames as $dirName)
    {
      $teamMdPath = $prefix . $dirName . '/TEAM.md';
      foreach ($treeItems as $item)
      {
        if ($item['path'] === $teamMdPath && $item['type'] === 'blob')
        {
          return true;
        }
      }
    }
    return false;
  }

  protected function findTeamDirectoriesInTree(array $tree, string $basePath): array
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

  protected function validateTeamDirectories(array $directories, array $tree): array
  {
    return array_values(array_filter($directories, function (array $dir) use ($tree)
    {
      $teamMdPath = $dir['path'] . '/TEAM.md';
      foreach ($tree as $item)
      {
        if ($item['path'] === $teamMdPath && $item['type'] === 'blob')
        {
          return true;
        }
      }
      return false;
    }));
  }

  protected function extractTeamFilesFromTree(array $tree, string $teamPath): array
  {
    $prefix = $teamPath . '/';
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
