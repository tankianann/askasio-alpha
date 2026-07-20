<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\ProviderQuotaExceededException;
use App\Services\ProviderQuota\ProviderQuotaService;
use PHPUnit\Framework\TestCase;
use Tests\Fakes\InMemoryProviderQuotaRepository;

final class ProviderQuotaServiceTest extends TestCase
{
    public function testReservationIsReconciledToActualUsage(): void
    {
        $repository = new InMemoryProviderQuotaRepository();
        $service = new ProviderQuotaService($repository, 1_000, 5_000, 500, 2_500, 900);
        $reservation = $service->reserveRetrieve(7, str_repeat('a', 100));

        $reserved = $service->snapshots([7]);
        self::assertSame(100, $reserved['global']->dailyReserved);
        self::assertSame(100, $reserved['api_keys'][7]->dailyReserved);

        $reported = $service->reconcile($reservation, 12);
        $reconciled = $service->snapshots([7]);

        self::assertSame(12, $reconciled['global']->dailyConsumed);
        self::assertSame(0, $reconciled['global']->dailyReserved);
        self::assertSame(12, $reconciled['api_keys'][7]->dailyConsumed);
        self::assertSame(488, $reported['quota_daily_remaining_tokens']);
        self::assertSame(12, $reported['quota_charged_tokens']);
    }

    public function testPerKeyBudgetCannotBeOversubscribed(): void
    {
        $service = new ProviderQuotaService(
            new InMemoryProviderQuotaRepository(),
            1_000,
            5_000,
            100,
            500,
            900,
        );
        $service->reserveRetrieve(7, str_repeat('a', 60));

        $this->expectException(ProviderQuotaExceededException::class);
        $service->reserveRetrieve(7, str_repeat('b', 41));
    }

    public function testGlobalBudgetIsSharedAcrossKeys(): void
    {
        $service = new ProviderQuotaService(
            new InMemoryProviderQuotaRepository(),
            100,
            500,
            1_000,
            5_000,
            900,
        );
        $service->reserveRetrieve(7, str_repeat('a', 70));

        $this->expectException(ProviderQuotaExceededException::class);
        $service->reserveRetrieve(8, str_repeat('b', 31));
    }

    public function testUnlimitedBudgetsStillReportUsage(): void
    {
        $service = new ProviderQuotaService(new InMemoryProviderQuotaRepository(), 0, 0, 0, 0, 900);
        $reservation = $service->reserveRetrieve(1, 'question');
        $usage = $service->reconcile($reservation, 3);

        self::assertSame(3, $usage['quota_charged_tokens']);
        self::assertArrayNotHasKey('quota_daily_remaining_tokens', $usage);
        self::assertArrayNotHasKey('quota_monthly_remaining_tokens', $usage);
    }
}
