<?php

declare(strict_types=1);

namespace Thinkliveid\Crew\Install\Detection;

use Thinkliveid\Crew\Install\Contracts\DetectionStrategy;
use Thinkliveid\Crew\Enums\Platform;

readonly class CompositeDetectionStrategy implements DetectionStrategy
{
  /**
   * @param DetectionStrategy[] $strategies
   */
  public function __construct(private array $strategies)
  {
    //
  }

  public function detect(array $config, ?Platform $platform = null): bool
  {
    foreach ($this->strategies as $strategy)
    {
      if ($strategy->detect($config, $platform))
      {
        return true;
      }
    }

    return false;
  }
}