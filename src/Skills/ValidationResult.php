<?php

declare(strict_types=1);

namespace Thinkliveid\Crew\Skills;

class ValidationResult
{
  /**
   * @param array<string> $errors
   * @param array<string, mixed> $frontmatter
   */
  public function __construct(
    public readonly bool $valid,
    public readonly array $errors = [],
    public readonly array $frontmatter = [],
    public readonly string $body = '',
  )
  {
    //
  }

  public function name(): ?string
  {
    return $this->frontmatter['name'] ?? null;
  }

  public function description(): ?string
  {
    return $this->frontmatter['description'] ?? null;
  }

  /**
   * @param array<string> $errors
   */
  public static function invalid(array $errors): self
  {
    return new self(valid: false, errors: $errors);
  }
}
