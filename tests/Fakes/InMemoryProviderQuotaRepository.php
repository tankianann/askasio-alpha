<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Domain\ProviderQuota\ProviderQuotaReservation;
use App\Exceptions\ProviderQuotaExceededException;
use App\Repositories\ProviderQuotaRepositoryInterface;
use DateTimeImmutable;

final class InMemoryProviderQuotaRepository implements ProviderQuotaRepositoryInterface
{
    /** @var array<string, array{consumed: int, reserved: int}> */
    private array $buckets = [];

    /** @var array<string, array{api_key_id: int, reserved: int, status: string, daily: string, monthly: string, expires_at: DateTimeImmutable}> */
    private array $reservations = [];

    public int $reservationAttempts = 0;

    public function reserve(
        int $apiKeyId,
        string $operation,
        int $tokens,
        array $limits,
        DateTimeImmutable $now,
        DateTimeImmutable $expiresAt,
    ): ProviderQuotaReservation {
        ++$this->reservationAttempts;
        $daily = $now->format('Y-m-d');
        $monthly = $now->format('Y-m-01');
        $windows = [
            ['key' => "global:daily:{$daily}", 'limit' => $limits['global_daily']],
            ['key' => "global:monthly:{$monthly}", 'limit' => $limits['global_monthly']],
            ['key' => "api_key:{$apiKeyId}:daily:{$daily}", 'limit' => $limits['api_key_daily']],
            ['key' => "api_key:{$apiKeyId}:monthly:{$monthly}", 'limit' => $limits['api_key_monthly']],
        ];

        foreach ($windows as $window) {
            $bucket = $this->buckets[$window['key']] ?? ['consumed' => 0, 'reserved' => 0];

            if ($window['limit'] > 0 && $bucket['consumed'] + $bucket['reserved'] + $tokens > $window['limit']) {
                throw new ProviderQuotaExceededException('The provider token budget has been exhausted.');
            }
        }

        foreach ($windows as $window) {
            $this->buckets[$window['key']] ??= ['consumed' => 0, 'reserved' => 0];
            $this->buckets[$window['key']]['reserved'] += $tokens;
        }

        $id = bin2hex(random_bytes(16));
        $this->reservations[$id] = [
            'api_key_id' => $apiKeyId,
            'reserved' => $tokens,
            'status' => 'active',
            'daily' => $daily,
            'monthly' => $monthly,
            'expires_at' => $expiresAt,
        ];

        return new ProviderQuotaReservation($id, $apiKeyId, $operation, $tokens);
    }

    public function reconcile(string $reservationId, int $actualTokens, bool $estimated = false): void
    {
        $reservation = $this->reservations[$reservationId] ?? null;

        if ($reservation === null || $reservation['status'] !== 'active') {
            return;
        }

        $this->charge($reservationId, $actualTokens);
    }

    public function reconcileExpired(int $limit): int
    {
        $now = new DateTimeImmutable('now');
        $count = 0;

        foreach ($this->reservations as $id => $reservation) {
            if ($count >= $limit) {
                break;
            }

            if ($reservation['status'] === 'active' && $reservation['expires_at'] < $now) {
                $this->charge($id, $reservation['reserved']);
                ++$count;
            }
        }

        return $count;
    }

    public function usage(array $apiKeyIds, DateTimeImmutable $now): array
    {
        $daily = $now->format('Y-m-d');
        $monthly = $now->format('Y-m-01');
        $usage = [
            'global' => [
                'daily' => $this->buckets["global:daily:{$daily}"] ?? ['consumed' => 0, 'reserved' => 0],
                'monthly' => $this->buckets["global:monthly:{$monthly}"] ?? ['consumed' => 0, 'reserved' => 0],
            ],
        ];

        foreach ($apiKeyIds as $apiKeyId) {
            $usage['api_key:' . $apiKeyId] = [
                'daily' => $this->buckets["api_key:{$apiKeyId}:daily:{$daily}"] ?? ['consumed' => 0, 'reserved' => 0],
                'monthly' => $this->buckets["api_key:{$apiKeyId}:monthly:{$monthly}"] ?? ['consumed' => 0, 'reserved' => 0],
            ];
        }

        return $usage;
    }

    private function charge(string $reservationId, int $actualTokens): void
    {
        $reservation = $this->reservations[$reservationId];
        $windows = [
            "global:daily:{$reservation['daily']}",
            "global:monthly:{$reservation['monthly']}",
            "api_key:{$reservation['api_key_id']}:daily:{$reservation['daily']}",
            "api_key:{$reservation['api_key_id']}:monthly:{$reservation['monthly']}",
        ];

        foreach ($windows as $window) {
            $this->buckets[$window]['reserved'] = max(
                0,
                $this->buckets[$window]['reserved'] - $reservation['reserved'],
            );
            $this->buckets[$window]['consumed'] += $actualTokens;
        }

        unset($this->reservations[$reservationId]);
    }
}
