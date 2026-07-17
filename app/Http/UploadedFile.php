<?php

declare(strict_types=1);

namespace App\Http;

use RuntimeException;

final class UploadedFile
{
    public function __construct(
        private readonly string $originalName,
        private readonly string $temporaryPath,
        private readonly int $error,
        private readonly int $reportedSize,
    ) {
    }

    /**
     * @param array<string, mixed> $files
     * @return array<string, self>
     */
    public static function fromGlobals(array $files): array
    {
        $uploads = [];

        foreach ($files as $name => $file) {
            if (!is_array($file) || is_array($file['name'] ?? null)) {
                continue;
            }

            $uploads[(string) $name] = new self(
                (string) ($file['name'] ?? ''),
                (string) ($file['tmp_name'] ?? ''),
                (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE),
                (int) ($file['size'] ?? 0),
            );
        }

        return $uploads;
    }

    public function originalName(): string
    {
        return $this->originalName;
    }

    public function error(): int
    {
        return $this->error;
    }

    public function reportedSize(): int
    {
        return $this->reportedSize;
    }

    public function actualSize(): int
    {
        $size = is_file($this->temporaryPath) ? filesize($this->temporaryPath) : false;

        return $size === false ? 0 : $size;
    }

    public function path(): string
    {
        return $this->temporaryPath;
    }

    public function sha256(): string
    {
        $hash = hash_file('sha256', $this->temporaryPath);

        if (!is_string($hash)) {
            throw new RuntimeException('The uploaded file could not be hashed.');
        }

        return $hash;
    }

    public function moveTo(string $destination): void
    {
        if (!is_uploaded_file($this->temporaryPath)) {
            throw new RuntimeException('The temporary file is not a valid HTTP upload.');
        }

        if (!move_uploaded_file($this->temporaryPath, $destination)) {
            throw new RuntimeException('The uploaded file could not be stored.');
        }
    }
}
