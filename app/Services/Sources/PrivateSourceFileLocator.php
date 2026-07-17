<?php

declare(strict_types=1);

namespace App\Services\Sources;

use App\Ingestion\PermanentIngestionException;

final class PrivateSourceFileLocator
{
    public function __construct(private readonly string $rootDirectory)
    {
    }

    public function locate(string $relativePath): string
    {
        if ($relativePath === '' || str_contains($relativePath, '..') || str_starts_with($relativePath, '/')) {
            throw new PermanentIngestionException('The stored source path is invalid.');
        }

        $root = realpath($this->rootDirectory);
        $path = realpath(
            rtrim($this->rootDirectory, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $relativePath),
        );

        if ($root === false || $path === false || !is_file($path)
            || !str_starts_with($path, $root . DIRECTORY_SEPARATOR)) {
            throw new PermanentIngestionException('The stored source file is missing or outside private storage.');
        }

        return $path;
    }
}
