<?php

declare(strict_types=1);

namespace App\Domain\Api;

final class ApiRequestLog
{
    public readonly ApiAccessMethod $accessMethod;

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
        ?ApiAccessMethod $accessMethod = null,
        public readonly ?int $chatbotApiKeyId = null,
        public readonly ?string $chatbotApiKeyName = null,
        public readonly ?string $chatbotApiKeyPrefix = null,
        public readonly ?int $chatbotId = null,
        public readonly ?string $chatbotName = null,
    ) {
        $this->accessMethod = $accessMethod
            ?? ($apiKeyId !== null ? ApiAccessMethod::GeneralApiKey : ApiAccessMethod::Unauthenticated);
    }
}
