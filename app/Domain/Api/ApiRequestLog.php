<?php

declare(strict_types=1);

namespace App\Domain\Api;

final class ApiRequestLog
{
    /** @param array<string, int|float> $usage */
    public function __construct(
        public readonly string $requestId,
        public readonly ?int $apiKeyId,
        public readonly string $ipHash,
        public readonly string $method,
        public readonly string $endpoint,
        public readonly int $statusCode,
        public readonly int $durationMilliseconds,
        public readonly ?string $errorCategory,
        public readonly array $usage = [],
        public readonly ?string $createdAt = null,
        public readonly ?string $apiKeyName = null,
        public readonly ?string $apiKeyPrefix = null,
    ) {
    }
}
