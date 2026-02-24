<?php

declare(strict_types=1);

namespace Thinkliveid\Crew\Support;

class Arr
{
  /**
   * Get an item from an array using "dot" notation.
   */
  public static function get(array $array, string $key, mixed $default = null): mixed
  {
    if (array_key_exists($key, $array))
    {
      return $array[$key];
    }

    foreach (explode('.', $key) as $segment)
    {
      if (!is_array($array) || !array_key_exists($segment, $array))
      {
        return $default;
      }

      $array = $array[$segment];
    }

    return $array;
  }

  /**
   * Set an item on an array using "dot" notation.
   */
  public static function set(array &$array, string $key, mixed $value): void
  {
    $keys = explode('.', $key);
    while (count($keys) > 1)
    {
      $segment = array_shift($keys);

      if (!isset($array[$segment]) || !is_array($array[$segment]))
      {
        $array[$segment] = [];
      }

      $array = &$array[$segment];
    }

    $array[array_shift($keys)] = $value;
  }
}
