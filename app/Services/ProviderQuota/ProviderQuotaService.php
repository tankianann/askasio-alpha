<?php

declare(strict_types=1);

namespace App\Services\ProviderQuota;

use App\Domain\ProviderQuota\ProviderQuotaReservation;
use App\Domain\ProviderQuota\ProviderQuotaSnapshot;
use App\Repositories\ProviderQuotaRepositoryInterface;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class ProviderQuotaService
{
    private const PROMPT_OVERHEAD_RESERVATION_TOKENS = 2_048;

    /** @var array{global_daily: int, global_monthly: int, api_key_daily: int, api_key_monthly: int} */
    private readonly array $limits;

    public function __construct(
        private readonly ProviderQuotaRepositoryInterface $repository,
        int $globalDailyTokens,
        int $globalMonthlyTokens,
        int $apiKeyDailyTokens,
        int $apiKeyMonthlyTokens,
        private readonly int $reservationTtlSeconds,
    ) {
        $limits = [$globalDailyTokens, $globalMonthlyTokens, $apiKeyDailyTokens, $apiKeyMonthlyTokens];

        if (array_filter($limits, static fn (int $limit): bool => $limit < 0) !== []) {
            throw new InvalidArgumentException('Provider token limits must be zero or positive integers.');
        }

        if ($this->reservationTtlSeconds < 60 || $this->reservationTtlSeconds > 3600) {
            throw new InvalidArgumentException('Provider quota reservation TTL must be between 60 and 3600 seconds.');
        }

        $this->limits = [
            'global_daily' => $globalDailyTokens,
            'global_monthly' => $globalMonthlyTokens,
            'api_key_daily' => $apiKeyDailyTokens,
            'api_key_monthly' => $apiKeyMonthlyTokens,
        ];
    }

    public function reserveRetrieve(int $apiKeyId, string $query): ProviderQuotaReservation
    {
        return $this->reserve($apiKeyId, 'retrieve', max(1, strlen($query)));
    }

    public function reserveChat(
        int $apiKeyId,
        string $question,
        int $maximumContextTokens,
        int $maximumOutputTokens,
    ): ProviderQuotaReservation {
        $questionBytes = max(1, strlen($question));
        $tokens = ($questionBytes * 2)
            + ($maximumContextTokens * 4)
            + $maximumOutputTokens
            + self::PROMPT_OVERHEAD_RESERVATION_TOKENS;

        return $this->reserve($apiKeyId, 'chat', $tokens);
    }

    /** @return array<string, int> */
    public function reconcile(
        ProviderQuotaReservation $reservation,
        int $actualTokens,
        bool $estimated = false,
    ): array {
        $actualTokens = max(0, $actualTokens);
        $this->repository->reconcile($reservation->id, $actualTokens, $estimated);
        $snapshots = $this->snapshots([$reservation->apiKeyId]);
        $global = $snapshots['global'];
        $apiKey = $snapshots['api_keys'][$reservation->apiKeyId];
        $dailyRemaining = $this->minimumRemaining($global->dailyRemaining(), $apiKey->dailyRemaining());
        $monthlyRemaining = $this->minimumRemaining($global->monthlyRemaining(), $apiKey->monthlyRemaining());

        return array_filter([
            'quota_charged_tokens' => $actualTokens,
            'quota_reserved_tokens' => $reservation->reservedTokens,
            'quota_daily_remaining_tokens' => $dailyRemaining,
            'quota_monthly_remaining_tokens' => $monthlyRemaining,
        ], static fn (?int $value): bool => $value !== null);
    }

    /**
     * @param list<int> $apiKeyIds
     * @return array{global: ProviderQuotaSnapshot, api_keys: array<int, ProviderQuotaSnapshot>}
     */
    public function snapshots(array $apiKeyIds): array
    {
        $this->repository->reconcileExpired(100);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $usage = $this->repository->usage($apiKeyIds, $now);
        $global = $this->snapshot(
            $usage['global'] ?? null,
            $this->limits['global_daily'],
            $this->limits['global_monthly'],
        );
        $keys = [];

        foreach ($apiKeyIds as $apiKeyId) {
            $keys[$apiKeyId] = $this->snapshot(
                $usage['api_key:' . $apiKeyId] ?? null,
                $this->limits['api_key_daily'],
                $this->limits['api_key_monthly'],
            );
        }

        return ['global' => $global, 'api_keys' => $keys];
    }

    public function reconcileExpired(int $limit = 100): int
    {
        return $this->repository->reconcileExpired($limit);
    }

    private function reserve(int $apiKeyId, string $operation, int $tokens): ProviderQuotaReservation
    {
        $this->repository->reconcileExpired(50);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return $this->repository->reserve(
            $apiKeyId,
            $operation,
            $tokens,
            $this->limits,
            $now,
            $now->modify('+' . $this->reservationTtlSeconds . ' seconds'),
        );
    }

    /** @param array{daily: array{consumed: int, reserved: int}, monthly: array{consumed: int, reserved: int}}|null $usage */
    private function snapshot(?array $usage, int $dailyLimit, int $monthlyLimit): ProviderQuotaSnapshot
    {
        return new ProviderQuotaSnapshot(
            $usage['daily']['consumed'] ?? 0,
            $usage['daily']['reserved'] ?? 0,
            $dailyLimit,
            $usage['monthly']['consumed'] ?? 0,
            $usage['monthly']['reserved'] ?? 0,
            $monthlyLimit,
        );
    }

    private function minimumRemaining(?int $left, ?int $right): ?int
    {
        if ($left === null) {
            return $right;
        }

        if ($right === null) {
            return $left;
        }

        return min($left, $right);
    }
}
