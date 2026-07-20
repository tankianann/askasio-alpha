<?php

declare(strict_types=1);

namespace App\Domain\ProviderQuota;

final class ProviderQuotaReservation
{
    public function __construct(
        public readonly string $id,
        public readonly int $apiKeyId,
        public readonly string $operation,
        public readonly int $reservedTokens,
    ) {
    }
}
