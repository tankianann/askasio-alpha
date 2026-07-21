<?php

declare(strict_types=1);

namespace App\Domain\ProviderQuota;

final readonly class AiUsageQuery
{
    public function __construct(
        public string $dateFrom,
        public string $dateTo,
        public string $fromUtc,
        public string $beforeUtc,
    ) {
    }

    /** @return array<string, string> */
    public function queryParameters(): array
    {
        return ['date_from' => $this->dateFrom, 'date_to' => $this->dateTo];
    }
}
