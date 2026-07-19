<?php

declare(strict_types=1);

namespace App\Domain\Api;

final readonly class ApiRequestLogPurgeSnapshot
{
    public function __construct(
        public ApiRequestLogPurgeCriteria $criteria,
        public int $recordCount,
        public ?int $maximumId,
    ) {
        if ($recordCount < 0 || ($recordCount > 0 && $maximumId === null)) {
            throw new \InvalidArgumentException('The purge snapshot is inconsistent.');
        }
    }
}
