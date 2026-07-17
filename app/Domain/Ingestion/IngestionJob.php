<?php

declare(strict_types=1);

namespace App\Domain\Ingestion;

final class IngestionJob
{
    public function __construct(
        public readonly int $id,
        public readonly int $sourceVersionId,
        public readonly string $jobType,
        public readonly JobStatus $status,
        public readonly int $priority,
        public readonly int $attempts,
        public readonly int $maxAttempts,
        public readonly string $availableAt,
        public readonly ?string $reservedAt,
        public readonly ?string $reservedBy,
        public readonly ?string $lastError,
        public readonly string $createdAt,
        public readonly string $updatedAt,
        public readonly ?string $completedAt,
        public readonly ?string $failedAt,
        public readonly ?int $sourceId = null,
        public readonly ?int $versionNumber = null,
        public readonly ?string $sourceName = null,
    ) {
    }
}
