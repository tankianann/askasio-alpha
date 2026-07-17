<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\ConfigurationException;

final class Config
{
    /** @param array<string, mixed> $values */
    public function __construct(private readonly array $values)
    {
    }

    public static function load(string $directory): self
    {
        if (!is_dir($directory)) {
            throw new ConfigurationException('Configuration directory does not exist.');
        }

        $values = [];
        $files = glob(rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.php');

        if ($files === false) {
            throw new ConfigurationException('Unable to read configuration directory.');
        }

        sort($files);

        foreach ($files as $file) {
            $section = basename($file, '.php');
            $configuration = require $file;

            if (!is_array($configuration)) {
                throw new ConfigurationException(sprintf('Configuration file %s must return an array.', basename($file)));
            }

            $values[$section] = $configuration;
        }

        return new self($values);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->values;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    public function requireString(string $key): string
    {
        $value = $this->get($key);

        if (!is_string($value) || $value === '') {
            throw new ConfigurationException(sprintf('Configuration value %s must be a non-empty string.', $key));
        }

        return $value;
    }

    public function requireInt(string $key): int
    {
        $value = $this->get($key);

        if (!is_int($value)) {
            throw new ConfigurationException(sprintf('Configuration value %s must be an integer.', $key));
        }

        return $value;
    }
}
