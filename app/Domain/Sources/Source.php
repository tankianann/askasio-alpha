<?php

declare(strict_types=1);

namespace App\Domain\Sources;

final class Source
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly SourceType $type,
        public readonly SourceStatus $status,
        public readonly ?int $activeVersionId,
        public readonly string $createdAt,
        public readonly string $updatedAt,
        public readonly ?string $deletedAt,
        public readonly int $versionCount = 0,
        public readonly ?ProcessingStatus $latestProcessingStatus = null,
    ) {
    }

    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }
}
