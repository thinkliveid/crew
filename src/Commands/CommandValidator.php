<?php

declare(strict_types=1);

namespace Thinkliveid\Crew\Commands;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Thinkliveid\Crew\Skills\ValidationResult;

class CommandValidator
{
  /**
   * Validate a single .md file as a slash command definition.
   *
   * Commands are identified by their filename. Frontmatter is required and
   * must provide a `description`. An optional `name` field, if present,
   * must match the filename stem.
   */
  public function validate(string $filePath): ValidationResult
  {
    $errors = [];
    $fileName = pathinfo($filePath, PATHINFO_FILENAME);

    if (!file_exists($filePath))
    {
      return ValidationResult::invalid(['Command file not found']);
    }

    if (!str_ends_with($filePath, '.md'))
    {
      return ValidationResult::invalid(['Command file must have .md extension']);
    }

    $content = file_get_contents($filePath);

    if ($content === false || trim($content) === '')
    {
      return ValidationResult::invalid(['Command file is empty']);
    }

    if (!preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $fileName) || str_contains($fileName, '--'))
    {
      return ValidationResult::invalid([
        sprintf('filename "%s" must be lowercase alphanumeric with hyphens, no leading/trailing hyphen, no consecutive hyphens', $fileName),
      ]);
    }

    if (!preg_match('/\A---\r?\n(.*?)\r?\n---\r?\n?(.*)\z/s', $content, $matches))
    {
      return ValidationResult::invalid(['Command file missing YAML frontmatter (--- delimiters)']);
    }

    $yamlRaw = $matches[1];
    $body = $matches[2];

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

    if (isset($frontmatter['name']))
    {
      if (!is_string($frontmatter['name']))
      {
        $errors[] = 'name must be a string';
      }
      elseif ($frontmatter['name'] !== $fileName)
      {
        $errors[] = sprintf('name "%s" does not match filename "%s"', $frontmatter['name'], $fileName);
      }
    }

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

    if (isset($frontmatter['allowed-tools']) && !is_string($frontmatter['allowed-tools']))
    {
      $errors[] = 'allowed-tools must be a string';
    }

    if (isset($frontmatter['argument-hint']) && !is_string($frontmatter['argument-hint']))
    {
      $errors[] = 'argument-hint must be a string';
    }

    if (isset($frontmatter['model']) && !is_string($frontmatter['model']))
    {
      $errors[] = 'model must be a string';
    }

    if (isset($frontmatter['metadata']) && !is_array($frontmatter['metadata']))
    {
      $errors[] = 'metadata must be a mapping';
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

    $frontmatter['name'] ??= $fileName;

    return new ValidationResult(
      valid: true,
      errors: [],
      frontmatter: $frontmatter,
      body: $body,
    );
  }
}
