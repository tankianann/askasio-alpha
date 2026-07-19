<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Domain\Sources\ProcessingStatus;
use App\Domain\Sources\Source;
use App\Domain\Sources\SourceListAvailability;
use App\Domain\Sources\SourceListQuery;
use App\Domain\Sources\SourceListSort;
use App\Domain\Sources\SourceStatus;
use App\Domain\Sources\SourceType;
use App\Domain\Sources\SourceVersion;
use App\Repositories\SourceRepositoryInterface;
use Closure;
use App\Support\Pagination\PaginatedResult;
use App\Support\SortDirection;

final class InMemorySourceRepository implements SourceRepositoryInterface
{
    public bool $inFlightJobs = false;

    /** @var array<int, Source> */
    private array $sources = [];

    /** @var array<int, list<SourceVersion>> */
    private array $versions = [];

    public function all(): array
    {
        return array_values($this->sources);
    }

    public function paginate(SourceListQuery $query): PaginatedResult
    {
        $sources = array_values(array_filter($this->sources, static function (Source $source) use ($query): bool {
            if ($query->search !== null && mb_stripos($source->name, $query->search) === false) {
                return false;
            }

            if ($query->type !== null && $source->type !== $query->type) {
                return false;
            }

            if ($query->availability === SourceListAvailability::Enabled
                && ($source->status !== SourceStatus::Enabled || $source->isDeleted())) {
                return false;
            }

            if ($query->availability === SourceListAvailability::Disabled
                && ($source->status !== SourceStatus::Disabled || $source->isDeleted())) {
                return false;
            }

            if ($query->availability === SourceListAvailability::Deleted && !$source->isDeleted()) {
                return false;
            }

            return $query->processingStatus === null || $source->latestProcessingStatus === $query->processingStatus;
        }));
        $direction = $query->direction === SortDirection::Ascending ? 1 : -1;
        usort($sources, static function (Source $left, Source $right) use ($query, $direction): int {
            $comparison = match ($query->sort) {
                SourceListSort::Updated => strcmp($left->updatedAt, $right->updatedAt),
                SourceListSort::Name => strcasecmp($left->name, $right->name),
                SourceListSort::Type => strcmp($left->type->value, $right->type->value),
                SourceListSort::Availability => strcmp(
                    ($left->deletedAt !== null ? 'deleted' : $left->status->value),
                    ($right->deletedAt !== null ? 'deleted' : $right->status->value),
                ),
                SourceListSort::Processing => strcmp(
                    $left->latestProcessingStatus?->value ?? '',
                    $right->latestProcessingStatus?->value ?? '',
                ),
                SourceListSort::Revisions => $left->versionCount <=> $right->versionCount,
            };

            return ($comparison !== 0 ? $comparison : $left->id <=> $right->id) * $direction;
        });
        $total = count($sources);
        $pageRequest = $query->pagination->clampToTotal($total);

        return new PaginatedResult(
            array_slice($sources, $pageRequest->offset(), $pageRequest->perPage),
            $total,
            $pageRequest,
        );
    }

    public function findById(int $id): ?Source
    {
        return $this->sources[$id] ?? null;
    }

    public function lockById(int $id): ?Source
    {
        return $this->findById($id);
    }

    public function findVersionById(int $id): ?SourceVersion
    {
        foreach ($this->versions as $versions) {
            foreach ($versions as $version) {
                if ($version->id === $id) {
                    return $version;
                }
            }
        }

        return null;
    }

    public function versionsForSource(int $sourceId): array
    {
        return array_reverse($this->versions[$sourceId] ?? []);
    }

    public function countEnabled(): int
    {
        return count(array_filter(
            $this->sources,
            static fn (Source $source): bool => $source->status === SourceStatus::Enabled && !$source->isDeleted(),
        ));
    }

    public function createSource(string $name, SourceType $type): Source
    {
        $id = count($this->sources) + 1;
        $source = new Source(
            $id,
            $name,
            $type,
            SourceStatus::Enabled,
            null,
            '2026-07-17 00:00:00.000000',
            '2026-07-17 00:00:00.000000',
            null,
        );
        $this->sources[$id] = $source;

        return $source;
    }

    public function createUrlVersion(int $sourceId, string $url): SourceVersion
    {
        return $this->addVersion($sourceId, null, $url, null, null, null, null);
    }

    public function createFileVersion(
        int $sourceId,
        string $originalFilename,
        string $storedFilePath,
        string $contentHash,
        string $mimeType,
        int $fileSize,
    ): SourceVersion {
        return $this->addVersion(
            $sourceId,
            $originalFilename,
            null,
            $storedFilePath,
            $contentHash,
            $mimeType,
            $fileSize,
        );
    }

    public function disable(int $id): void
    {
        $this->replaceStatus($id, SourceStatus::Disabled);
    }

    public function enable(int $id): void
    {
        $this->replaceStatus($id, SourceStatus::Enabled);
    }

    public function softDelete(int $id): void
    {
        $source = $this->sources[$id];
        $this->sources[$id] = new Source(
            $source->id,
            $source->name,
            $source->type,
            SourceStatus::Disabled,
            $source->activeVersionId,
            $source->createdAt,
            $source->updatedAt,
            '2026-07-17 00:00:00.000000',
            $source->versionCount,
            $source->latestProcessingStatus,
        );
    }

    public function hasInFlightJobs(int $sourceId): bool
    {
        return $this->inFlightJobs;
    }

    public function permanentlyDelete(int $id): void
    {
        unset($this->sources[$id], $this->versions[$id]);
    }

    public function transaction(Closure $operation): mixed
    {
        return $operation();
    }

    private function addVersion(
        int $sourceId,
        ?string $filename,
        ?string $url,
        ?string $path,
        ?string $hash,
        ?string $mime,
        ?int $size,
    ): SourceVersion {
        $number = count($this->versions[$sourceId] ?? []) + 1;
        $version = new SourceVersion(
            array_sum(array_map('count', $this->versions)) + 1,
            $sourceId,
            $number,
            $filename,
            $url,
            $path,
            null,
            $mime,
            $size,
            ProcessingStatus::Pending,
            null,
            '2026-07-17 00:00:00.000000',
            null,
            null,
            $hash,
        );
        $this->versions[$sourceId][] = $version;
        $source = $this->sources[$sourceId];
        $this->sources[$sourceId] = new Source(
            $source->id,
            $source->name,
            $source->type,
            $source->status,
            null,
            $source->createdAt,
            $source->updatedAt,
            null,
            $number,
            ProcessingStatus::Pending,
        );

        return $version;
    }

    private function replaceStatus(int $id, SourceStatus $status): void
    {
        $source = $this->sources[$id];
        $this->sources[$id] = new Source(
            $source->id,
            $source->name,
            $source->type,
            $status,
            $source->activeVersionId,
            $source->createdAt,
            $source->updatedAt,
            $source->deletedAt,
            $source->versionCount,
            $source->latestProcessingStatus,
        );
    }
}
