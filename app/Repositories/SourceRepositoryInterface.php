<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\Sources\Source;
use App\Domain\Sources\SourceType;
use App\Domain\Sources\SourceVersion;
use Closure;

interface SourceRepositoryInterface
{
    /** @return list<Source> */
    public function all(): array;

    public function findById(int $id): ?Source;

    /** @return list<SourceVersion> */
    public function versionsForSource(int $sourceId): array;

    public function countEnabled(): int;

    public function createSource(string $name, SourceType $type): Source;

    public function createUrlVersion(int $sourceId, string $url): SourceVersion;

    public function createFileVersion(
        int $sourceId,
        string $originalFilename,
        string $storedFilePath,
        string $contentHash,
        string $mimeType,
        int $fileSize,
    ): SourceVersion;

    public function disable(int $id): void;

    public function enable(int $id): void;

    public function softDelete(int $id): void;

    public function transaction(Closure $operation): mixed;
}
