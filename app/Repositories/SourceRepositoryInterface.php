<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\Sources\Source;
use App\Domain\Sources\SourceListQuery;
use App\Domain\Sources\SourceType;
use App\Domain\Sources\SourceVersion;
use Closure;
use App\Support\Pagination\PaginatedResult;

interface SourceRepositoryInterface
{
    /** @return list<Source> */
    public function all(): array;

    /** @return PaginatedResult<Source> */
    public function paginate(SourceListQuery $query): PaginatedResult;

    public function findById(int $id): ?Source;

    public function lockById(int $id): ?Source;

    public function findVersionById(int $id): ?SourceVersion;

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

    public function hasInFlightJobs(int $sourceId): bool;

    public function permanentlyDelete(int $id): void;

    public function transaction(Closure $operation): mixed;
}
