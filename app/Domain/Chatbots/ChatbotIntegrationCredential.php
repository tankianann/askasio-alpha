<?php

declare(strict_types=1);

namespace App\Domain\Chatbots;

use DateTimeImmutable;
use DateTimeZone;

final readonly class ChatbotIntegrationCredential
{
    /** @param list<int> $chatbotIds */
    public function __construct(
        public int $id, public int $createdByAdminId, public string $name, public string $visiblePrefix,
        public string $secretHash, public string $status, public array $chatbotIds, public int $requestCount,
        public int $providerTokens, public string $createdAt, public ?string $lastUsedAt,
        public ?string $expiresAt, public ?string $revokedAt,
    ) {
    }

    public function isUsable(): bool
    {
        return $this->status === 'active' && ($this->expiresAt === null
            || new DateTimeImmutable($this->expiresAt, new DateTimeZone('UTC')) > new DateTimeImmutable('now', new DateTimeZone('UTC')));
    }
}
