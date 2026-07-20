<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\ConfigurationException;

final class Env
{
    public static function string(string $key, ?string $default = null): ?string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        if ($value === false || $value === '') {
            return $default;
        }

        return (string) $value;
    }

    public static function int(string $key, int $default): int
    {
        $value = self::string($key);

        if ($value === null) {
            return $default;
        }

        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new ConfigurationException(sprintf('%s must be an integer.', $key));
        }

        return (int) $value;
    }

    public static function nullableInt(string $key): ?int
    {
        $value = self::string($key);

        if ($value === null) {
            return null;
        }

        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new ConfigurationException(sprintf('%s must be an integer.', $key));
        }

        return (int) $value;
    }

    public static function float(string $key, float $default): float
    {
        $value = self::string($key);

        if ($value === null) {
            return $default;
        }

        if (!is_numeric($value) || !is_finite((float) $value)) {
            throw new ConfigurationException(sprintf('%s must be a finite number.', $key));
        }

        return (float) $value;
    }

    public static function bool(string $key, bool $default): bool
    {
        $value = self::string($key);

        if ($value === null) {
            return $default;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

        if ($parsed === null) {
            throw new ConfigurationException(sprintf('%s must be a boolean.', $key));
        }

        return $parsed;
    }
}
