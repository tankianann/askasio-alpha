<?php

declare(strict_types=1);

namespace App\Services\Sources;

use App\Domain\Sources\SourceType;
use App\Http\UploadedFile;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
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

    public function stageSourceDirectory(int $sourceId): ?StagedSourceDirectory
    {
        if ($sourceId < 1) {
            throw new RuntimeException('Refusing to stage an invalid source directory.');
        }

        $root = realpath($this->rootDirectory);

        if ($root === false) {
            throw new RuntimeException('Private source storage is unavailable.');
        }

        $sourcePath = $root . DIRECTORY_SEPARATOR . $sourceId;

        if (is_link($sourcePath)) {
            throw new RuntimeException('The source storage directory is invalid.');
        }

        if (!file_exists($sourcePath)) {
            return null;
        }

        if (!is_dir($sourcePath)) {
            throw new RuntimeException('The source storage directory is invalid.');
        }

        $trashRoot = $root . DIRECTORY_SEPARATOR . '.trash';

        if (!is_dir($trashRoot) && !mkdir($trashRoot, 0700, true) && !is_dir($trashRoot)) {
            throw new RuntimeException('The private deletion staging directory could not be created.');
        }

        $stagedPath = $trashRoot . DIRECTORY_SEPARATOR . sprintf('source-%d-%s', $sourceId, bin2hex(random_bytes(16)));

        if (!rename($sourcePath, $stagedPath)) {
            throw new RuntimeException('The source files could not be staged for deletion.');
        }

        return new StagedSourceDirectory($sourcePath, $stagedPath);
    }

    public function restoreStagedDirectory(StagedSourceDirectory $directory): void
    {
        if (!is_dir($directory->stagedPath) || file_exists($directory->originalPath)) {
            throw new RuntimeException('The staged source files could not be restored safely.');
        }

        if (!rename($directory->stagedPath, $directory->originalPath)) {
            throw new RuntimeException('The staged source files could not be restored.');
        }
    }

    public function finalizeStagedDirectory(StagedSourceDirectory $directory): void
    {
        if (!is_dir($directory->stagedPath) || is_link($directory->stagedPath)) {
            throw new RuntimeException('The staged source directory is invalid.');
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory->stagedPath, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            $path = $item->getPathname();

            if ($item->isLink() || $item->isFile()) {
                if (!unlink($path)) {
                    throw new RuntimeException('A staged source file could not be removed.');
                }
            } elseif ($item->isDir() && !rmdir($path)) {
                throw new RuntimeException('A staged source directory could not be removed.');
            }
        }

        if (!rmdir($directory->stagedPath)) {
            throw new RuntimeException('The staged source directory could not be removed.');
        }
    }
}
