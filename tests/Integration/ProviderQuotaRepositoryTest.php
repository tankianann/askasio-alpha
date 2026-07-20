<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Database\Connection;
use App\Exceptions\ProviderQuotaExceededException;
use App\Repositories\PdoProviderQuotaRepository;
use App\Support\Config;
use DateTimeImmutable;
use DateTimeZone;
use Tests\Support\DatabaseIntegrationTestCase;
use Tests\Support\TestDatabase;

final class ProviderQuotaRepositoryTest extends DatabaseIntegrationTestCase
{
    public function testReservationsAtomicallyEnforceAndReconcileGlobalAndPerKeyBudgets(): void
    {
        $repository = $this->repository();
        $limits = [
            'global_daily' => 100,
            'global_monthly' => 500,
            'api_key_daily' => 90,
            'api_key_monthly' => 450,
        ];
        $now = new DateTimeImmutable('2026-07-20 12:00:00', new DateTimeZone('UTC'));
        $first = $repository->reserve(7, 'retrieve', 70, $limits, $now, $now->modify('+15 minutes'));

        try {
            $repository->reserve(8, 'retrieve', 31, $limits, $now, $now->modify('+15 minutes'));
            self::fail('The shared global reservation should prevent oversubscription.');
        } catch (ProviderQuotaExceededException) {
            self::assertTrue(true);
        }

        try {
            $repository->reserve(7, 'retrieve', 21, $limits, $now, $now->modify('+15 minutes'));
            self::fail('The per-key reservation should prevent oversubscription.');
        } catch (ProviderQuotaExceededException) {
            self::assertTrue(true);
        }

        $before = $repository->usage([7, 8], $now);
        self::assertSame(70, $before['global']['daily']['reserved']);
        self::assertSame(70, $before['api_key:7']['daily']['reserved']);

        $repository->reconcile($first->id, 12);
        $after = $repository->usage([7], $now);
        self::assertSame(12, $after['global']['daily']['consumed']);
        self::assertSame(0, $after['global']['daily']['reserved']);
        self::assertSame(12, $after['api_key:7']['daily']['consumed']);
        self::assertSame(0, (int) self::$database?->query('SELECT COUNT(*) FROM provider_quota_reservations')->fetchColumn());

        $repository->reserve(8, 'retrieve', 80, $limits, $now, $now->modify('+15 minutes'));
        $final = $repository->usage([8], $now);
        self::assertSame(92, $final['global']['daily']['consumed'] + $final['global']['daily']['reserved']);
    }

    public function testExpiredReservationIsConservativelyChargedOnce(): void
    {
        self::$database?->exec('DELETE FROM provider_quota_reservations');
        self::$database?->exec('DELETE FROM provider_quota_buckets');
        $repository = $this->repository();
        $limits = [
            'global_daily' => 1_000,
            'global_monthly' => 5_000,
            'api_key_daily' => 500,
            'api_key_monthly' => 2_500,
        ];
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $repository->reserve(7, 'chat', 120, $limits, $now, $now->modify('-1 second'));

        self::assertSame(1, $repository->reconcileExpired(50));
        self::assertSame(0, $repository->reconcileExpired(50));

        $usage = $repository->usage([7], $now);
        self::assertSame(120, $usage['global']['daily']['consumed']);
        self::assertSame(0, $usage['global']['daily']['reserved']);
        self::assertSame(0, (int) self::$database?->query('SELECT COUNT(*) FROM provider_quota_reservations')->fetchColumn());
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::$database?->exec('DELETE FROM provider_quota_reservations');
        self::$database?->exec('DELETE FROM provider_quota_buckets');
    }

    private function repository(): PdoProviderQuotaRepository
    {
        $environment = TestDatabase::applicationEnvironment();
        $config = new Config(['database' => [
            'host' => $environment['DB_HOST'],
            'port' => (int) $environment['DB_PORT'],
            'database' => $environment['DB_DATABASE'],
            'username' => $environment['DB_USERNAME'],
            'password' => $environment['DB_PASSWORD'],
            'charset' => 'utf8mb4',
        ]]);

        return new PdoProviderQuotaRepository(new Connection($config));
    }
}
