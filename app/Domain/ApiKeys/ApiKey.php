<?php

declare(strict_types=1);

namespace App\Domain\ApiKeys;

use DateTimeImmutable;
use DateTimeZone;

final class ApiKey
{
    public function __construct(
        public readonly int $id,
        public readonly int $createdByAdminId,
        public readonly string $name,
        public readonly string $visiblePrefix,
        public readonly string $secretHash,
        public readonly string $status,
        public readonly string $createdAt,
        public readonly ?string $lastUsedAt,
        public readonly ?string $expiresAt,
        public readonly ?string $revokedAt,
    ) {
    }

    public function isExpired(?DateTimeImmutable $now = null): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }

        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return new DateTimeImmutable($this->expiresAt, new DateTimeZone('UTC')) <= $now;
    }

    public function isUsable(?DateTimeImmutable $now = null): bool
    {
        return $this->status === 'active' && $this->revokedAt === null && !$this->isExpired($now);
    }

    public function displayStatus(): string
    {
        return $this->isExpired() ? 'expired' : $this->status;
    }
}
