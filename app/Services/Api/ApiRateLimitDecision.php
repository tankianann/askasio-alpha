<?php

declare(strict_types=1);

namespace App\Services\Api;

final class ApiRateLimitDecision
{
    public function __construct(
        public readonly bool $allowed,
        public readonly int $limit,
        public readonly int $remaining,
        public readonly int $retryAfterSeconds,
    ) {
    }
}
