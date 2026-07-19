<?php

declare(strict_types=1);

namespace App\Domain\Api;

final readonly class ApiRequestLogPurgeIntent
{
    public function __construct(
        public string $token,
        public ApiRequestLogPurgeSnapshot $snapshot,
        public int $createdAt,
    ) {
    }

    public function confirmationPhrase(): string
    {
        return sprintf(
            'DELETE %d %s',
            $this->snapshot->recordCount,
            $this->snapshot->recordCount === 1 ? 'REQUEST' : 'REQUESTS',
        );
    }
}
