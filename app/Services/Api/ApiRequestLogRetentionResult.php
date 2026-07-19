<?php

declare(strict_types=1);

namespace App\Services\Api;

final class ApiRequestLogRetentionResult
{
    public function __construct(
        public readonly bool $enabled,
        public readonly bool $lockAcquired,
        public readonly int $deletedRecords,
        public readonly int $batches,
        public readonly ?string $cutoffUtc,
    ) {
    }
}
