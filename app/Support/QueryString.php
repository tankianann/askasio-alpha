<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

final class QueryString
{
    /**
     * @param array<string, scalar|null> $parameters
     * @param array<string, scalar|null> $overrides
     */
    public static function build(array $parameters, array $overrides = []): string
    {
        $parameters = [...$parameters, ...$overrides];

        foreach ($parameters as $key => $value) {
            if (!is_string($key) || $key === '' || preg_match('/^[a-z][a-z0-9_]*$/', $key) !== 1) {
                throw new InvalidArgumentException('Query-string keys must use lowercase snake case.');
            }

            if ($value === null || $value === '') {
                unset($parameters[$key]);
                continue;
            }

            if (!is_scalar($value)) {
                throw new InvalidArgumentException('Query-string values must be scalar.');
            }
        }

        if ($parameters === []) {
            return '';
        }

        ksort($parameters);

        return '?' . http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @param array<string, scalar|null> $parameters
     * @param array<string, scalar|null> $overrides
     */
    public static function url(string $path, array $parameters, array $overrides = []): string
    {
        if (!str_starts_with($path, '/') || str_starts_with($path, '//')) {
            throw new InvalidArgumentException('Query-string URLs must use a local absolute path.');
        }

        return $path . self::build($parameters, $overrides);
    }
}
