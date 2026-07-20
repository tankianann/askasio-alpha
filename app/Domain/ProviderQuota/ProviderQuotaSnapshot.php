<?php

declare(strict_types=1);

namespace App\Domain\ProviderQuota;

final class ProviderQuotaSnapshot
{
    public function __construct(
        public readonly int $dailyConsumed,
        public readonly int $dailyReserved,
        public readonly int $dailyLimit,
        public readonly int $monthlyConsumed,
        public readonly int $monthlyReserved,
        public readonly int $monthlyLimit,
    ) {
    }

    public function dailyRemaining(): ?int
    {
        return $this->dailyLimit === 0
            ? null
            : max(0, $this->dailyLimit - $this->dailyConsumed - $this->dailyReserved);
    }

    public function monthlyRemaining(): ?int
    {
        return $this->monthlyLimit === 0
            ? null
            : max(0, $this->monthlyLimit - $this->monthlyConsumed - $this->monthlyReserved);
    }
}
