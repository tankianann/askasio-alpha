<?php

declare(strict_types=1);

namespace App\Domain\Api;

final readonly class ApiRequestLogPurgeResult
{
    public function __construct(
        public bool $lockAcquired,
        public int $deletedRecords,
        public int $batches,
    ) {
    }
}
