<?php

declare(strict_types=1);

namespace App\Services\Sources;

use App\Domain\Sources\SourceType;
use App\Http\UploadedFile;
use RuntimeException;

final class SourceFileStorage
{
    public function __construct(private readonly string $rootDirectory)
    {
    }

    public function store(UploadedFile $file, int $sourceId, SourceType $type): string
    {
        $directory = rtrim($this->rootDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $sourceId;

        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('The private source directory could not be created.');
        }

        $extension = $type === SourceType::Pdf ? 'pdf' : 'md';
        $filename = bin2hex(random_bytes(24)) . '.' . $extension;
        $destination = $directory . DIRECTORY_SEPARATOR . $filename;
        $file->moveTo($destination);
        chmod($destination, 0640);

        return $sourceId . '/' . $filename;
    }

    public function delete(string $relativePath): void
    {
        if ($relativePath === '' || str_contains($relativePath, '..') || str_starts_with($relativePath, '/')) {
            throw new RuntimeException('Refusing to delete an invalid source path.');
        }

        $path = rtrim($this->rootDirectory, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

        if (is_file($path) && !unlink($path)) {
            throw new RuntimeException('A failed source upload could not be cleaned up.');
        }
    }
}
