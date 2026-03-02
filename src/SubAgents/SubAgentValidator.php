<?php

declare(strict_types=1);

namespace Thinkliveid\Crew\SubAgents;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Thinkliveid\Crew\Skills\ValidationResult;

class SubAgentValidator
{
  /** @var array<int, string> */
  protected array $validModels = ['sonnet', 'opus', 'haiku', 'inherit'];

  /**
   * Validate a single .md file as a sub-agent definition.
   */
  public function validate(string $filePath): ValidationResult
  {
    $errors = [];
    $fileName = pathinfo($filePath, PATHINFO_FILENAME);

    // 1. File must exist and not be empty
    if (!file_exists($filePath))
    {
      return ValidationResult::invalid(['Sub-agent file not found']);
    }

    if (!str_ends_with($filePath, '.md'))
    {
      return ValidationResult::invalid(['Sub-agent file must have .md extension']);
    }

    $content = file_get_contents($filePath);

    if ($content === false || trim($content) === '')
    {
      return ValidationResult::invalid(['Sub-agent file is empty']);
    }

    // 2. Must have YAML frontmatter delimiters
    if (!preg_match('/\A---\r?\n(.*?)\r?\n---\r?\n?(.*)\z/s', $content, $matches))
    {
      return ValidationResult::invalid(['Sub-agent file missing YAML frontmatter (--- delimiters)']);
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

      if ($name !== $fileName)
      {
        $errors[] = sprintf('name "%s" does not match filename "%s"', $name, $fileName);
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

    // 6. Validate optional fields
    if (isset($frontmatter['model']))
    {
      if (!is_string($frontmatter['model']) || !in_array($frontmatter['model'], $this->validModels, true))
      {
        $errors[] = sprintf('model must be one of: %s', implode(', ', $this->validModels));
      }
    }

    if (isset($frontmatter['tools']) && !is_array($frontmatter['tools']))
    {
      $errors[] = 'tools must be an array';
    }

    if (isset($frontmatter['disallowedTools']) && !is_array($frontmatter['disallowedTools']))
    {
      $errors[] = 'disallowedTools must be an array';
    }

    if (isset($frontmatter['maxTurns']) && !is_int($frontmatter['maxTurns']))
    {
      $errors[] = 'maxTurns must be an integer';
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

    return new ValidationResult(
      valid: true,
      errors: [],
      frontmatter: $frontmatter,
      body: $body,
    );
  }
}
