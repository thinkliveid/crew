<?php

declare(strict_types=1);

namespace Thinkliveid\Crew\Teams;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Thinkliveid\Crew\Skills\ValidationResult;

class TeamValidator
{
  /**
   * Validate a team directory against the specification.
   */
  public function validate(string $teamDir): ValidationResult
  {
    $errors = [];
    $dirName = basename($teamDir);
    $teamMdPath = $teamDir . '/TEAM.md';

    // 1. TEAM.md must exist and not be empty
    if (!file_exists($teamMdPath))
    {
      return ValidationResult::invalid(['TEAM.md not found']);
    }

    $content = file_get_contents($teamMdPath);

    if ($content === false || trim($content) === '')
    {
      return ValidationResult::invalid(['TEAM.md is empty']);
    }

    // 2. Must have YAML frontmatter delimiters
    if (!preg_match('/\A---\r?\n(.*?)\r?\n---\r?\n?(.*)\z/s', $content, $matches))
    {
      return ValidationResult::invalid(['TEAM.md missing YAML frontmatter (--- delimiters)']);
    }

    $yamlRaw = $matches[1];
    $body = $matches[2];

    // 3. Parse YAML
    try
    {
      $frontmatter = Yaml::parse($yamlRaw);
    }
    catch (ParseException $e)
    {
      return ValidationResult::invalid([sprintf('Invalid YAML frontmatter: %s', $e->getMessage())]);
    }

    if (!is_array($frontmatter))
    {
      return ValidationResult::invalid(['YAML frontmatter must be a mapping']);
    }

    // 4. Validate 'name' (required)
    if (!isset($frontmatter['name']) || !is_string($frontmatter['name']))
    {
      $errors[] = 'Missing required field: name';
    }
    else
    {
      $name = $frontmatter['name'];

      if (mb_strlen($name) < 1 || mb_strlen($name) > 64)
      {
        $errors[] = 'name must be 1-64 characters';
      }

      if (!preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $name) || str_contains($name, '--'))
      {
        $errors[] = 'name must be lowercase alphanumeric with hyphens, no leading/trailing hyphen, no consecutive hyphens';
      }

      if ($name !== $dirName)
      {
        $errors[] = sprintf('name "%s" does not match directory name "%s"', $name, $dirName);
      }
    }

    // 5. Validate 'description' (required)
    if (!isset($frontmatter['description']) || !is_string($frontmatter['description']))
    {
      $errors[] = 'Missing required field: description';
    }
    else
    {
      $desc = $frontmatter['description'];

      if (mb_strlen(trim($desc)) < 1)
      {
        $errors[] = 'description must not be empty';
      }

      if (mb_strlen($desc) > 1024)
      {
        $errors[] = 'description must be at most 1024 characters';
      }
    }

    // 6. Validate 'metadata' (optional)
    if (isset($frontmatter['metadata']))
    {
      if (!is_array($frontmatter['metadata']))
      {
        $errors[] = 'metadata must be a mapping';
      }
    }

    if (!empty($errors))
    {
      return new ValidationResult(
        valid: false,
        errors: $errors,
        frontmatter: $frontmatter,
        body: $body,
      );
    }

    return new ValidationResult(
      valid: true,
      errors: [],
      frontmatter: $frontmatter,
      body: $body,
    );
  }
}
