<?php

declare(strict_types=1);

namespace Thinkliveid\Crew\SubAgents\Remote;

use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;
use Thinkliveid\Crew\Skills\Remote\GitHubRepository;
use Thinkliveid\Crew\Support\Config;

class GitHubSubAgentProvider
{
  protected string $defaultBranch = 'main';
  protected string $resolvedPath = '';
  protected ?array $cachedTree = null;

  /** @var array<int, string> */
  protected array $commonAgentPaths = [
    'agents',
    '.ai/agents',
    '.claude/agents',
  ];

  public function __construct(protected GitHubRepository $repository, protected Config $config)
  {
  }

  /**
   * @return array<string, RemoteSubAgent>
   */
  public function discoverSubAgents(): array
  {
    $tree = $this->fetchRepositoryTree();
    if ($tree === null)
    {
      return [];
    }

    $this->resolvedPath = $this->resolveAgentsPath();
    $mdFiles = $this->findAgentFilesInTree($tree['tree'], $this->resolvedPath);
    if (empty($mdFiles))
    {
      return [];
    }

    $validAgents = $this->validateAgentFiles($mdFiles);

    $result = [];
    foreach ($validAgents as $item)
    {
      $agent = new RemoteSubAgent(
        name: $item['name'],
        repo: $this->repository->fullName(),
        path: $item['path'],
      );
      $result[$agent->name] = $agent;
    }

    return $result;
  }

  public function downloadSubAgent(RemoteSubAgent $agent, string $targetPath): bool
  {
    if (!is_dir($targetPath) && !mkdir($targetPath, 0755, true) && !is_dir($targetPath))
    {
      return false;
    }

    $url = $this->buildRawFileUrl($agent->path);

    try
    {
      $response = $this->client()->get($url);
      $content = $response->getBody()->getContents();
      $targetFile = $targetPath . '/' . $agent->name . '.md';

      return file_put_contents($targetFile, $content) !== false;
    }
    catch (GuzzleException $e)
    {
      return false;
    }
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

  protected function resolveAgentsPath(): string
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

    // Check root level for .md files with valid frontmatter
    $rootMdFiles = array_filter($treeItems, fn(array $item): bool => $item['type'] === 'blob'
      && !str_contains((string)$item['path'], '/')
      && str_ends_with((string)$item['path'], '.md')
    );

    if ($this->hasValidAgentsAtPath($rootMdFiles))
    {
      return '';
    }

    foreach ($this->commonAgentPaths as $commonPath)
    {
      $topLevel = explode('/', $commonPath)[0];

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

      $mdFilesAtPath = array_filter($treeItems, fn(array $item): bool => $this->isDirectChildOf($item, $commonPath, 'blob')
        && str_ends_with((string)$item['path'], '.md')
      );

      if ($this->hasValidAgentsAtPath($mdFilesAtPath))
      {
        return $commonPath;
      }
    }

    return '';
  }

  /**
   * Check if any .md files at a path look like valid sub-agents (have frontmatter).
   * Downloads content to verify frontmatter exists.
   *
   * @param array<int, array<string, mixed>> $mdFiles
   */
  protected function hasValidAgentsAtPath(array $mdFiles): bool
  {
    foreach ($mdFiles as $file)
    {
      $path = (string)$file['path'];
      try
      {
        $response = $this->client()->get($this->buildRawFileUrl($path));
        $content = $response->getBody()->getContents();

        if (preg_match('/\A---\r?\n(.*?)\r?\n---/s', $content, $matches))
        {
          $frontmatter = Yaml::parse($matches[1]);
          if (is_array($frontmatter) && isset($frontmatter['name'], $frontmatter['description']))
          {
            return true;
          }
        }
      }
      catch (\Throwable $e)
      {
        continue;
      }
    }
    return false;
  }

  /**
   * Find .md blob files that are direct children of the given path.
   *
   * @return array<int, array{name: string, path: string}>
   */
  protected function findAgentFilesInTree(array $tree, string $basePath): array
  {
    $result = [];
    foreach ($tree as $item)
    {
      if ($this->isDirectChildOf($item, $basePath, 'blob') && str_ends_with((string)$item['path'], '.md'))
      {
        $result[] = [
          'name' => pathinfo(basename((string)$item['path']), PATHINFO_FILENAME),
          'path' => $item['path'],
        ];
      }
    }
    return array_values($result);
  }

  /**
   * Validate agent files by downloading and checking frontmatter.
   *
   * @param array<int, array{name: string, path: string}> $files
   * @return array<int, array{name: string, path: string}>
   */
  protected function validateAgentFiles(array $files): array
  {
    return array_values(array_filter($files, function (array $file): bool
    {
      try
      {
        $response = $this->client()->get($this->buildRawFileUrl($file['path']));
        $content = $response->getBody()->getContents();

        if (!preg_match('/\A---\r?\n(.*?)\r?\n---/s', $content, $matches))
        {
          return false;
        }

        $frontmatter = Yaml::parse($matches[1]);
        return is_array($frontmatter)
          && isset($frontmatter['name'], $frontmatter['description'])
          && is_string($frontmatter['name'])
          && is_string($frontmatter['description']);
      }
      catch (\Throwable $e)
      {
        return false;
      }
    }));
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

  protected function buildRawFileUrl(string $path): string
  {
    return sprintf('https://raw.githubusercontent.com/%s/%s/%s/%s',
      $this->repository->owner, $this->repository->repo, $this->defaultBranch, ltrim($path, '/'));
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
