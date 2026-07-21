<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\ProviderQuota\ProviderQuotaReservation;
use App\Domain\ProviderQuota\ProviderQuotaAttribution;
use DateTimeImmutable;

interface ProviderQuotaRepositoryInterface
{
    /** @param array{global_daily: int, global_monthly: int, api_key_daily: int, api_key_monthly: int} $limits */
    public function reserve(
        int $apiKeyId,
        string $operation,
        int $tokens,
        array $limits,
        DateTimeImmutable $now,
        DateTimeImmutable $expiresAt,
        ?ProviderQuotaAttribution $attribution = null,
    ): ProviderQuotaReservation;

    public function reconcile(string $reservationId, int $actualTokens, bool $estimated = false): void;

    public function reconcileExpired(int $limit): int;

    /**
     * @param list<int> $apiKeyIds
     * @return array<string, array{daily: array{consumed: int, reserved: int}, monthly: array{consumed: int, reserved: int}}>
     */
    public function usage(array $apiKeyIds, DateTimeImmutable $now): array;

    /**
     * @return array{daily: array{consumed: int, reserved: int}, monthly: array{consumed: int, reserved: int}}
     */
    public function usageAcrossApiKeys(DateTimeImmutable $now): array;
}
