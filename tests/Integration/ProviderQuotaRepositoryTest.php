<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Database\Connection;
use App\Domain\Api\ApiAccessMethod;
use App\Domain\ProviderQuota\ProviderQuotaAttribution;
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
        $first = $repository->reserve(
            7,
            'retrieve',
            70,
            $limits,
            $now,
            $now->modify('+15 minutes'),
            new ProviderQuotaAttribution(ApiAccessMethod::GeneralApiKey, 7),
        );

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
        $usageRecord = self::$database->query(
            'SELECT access_method, api_key_id, chatbot_api_key_id, chatbot_id, operation, usage_tokens, is_estimated
             FROM ai_usage_records WHERE reservation_id = ' . self::$database->quote($first->id),
        )->fetch();
        self::assertIsArray($usageRecord);
        self::assertSame('general_api_key', $usageRecord['access_method']);
        self::assertSame(7, (int) $usageRecord['api_key_id']);
        self::assertNull($usageRecord['chatbot_api_key_id']);
        self::assertNull($usageRecord['chatbot_id']);
        self::assertSame('retrieve', $usageRecord['operation']);
        self::assertSame(12, (int) $usageRecord['usage_tokens']);
        self::assertSame(0, (int) $usageRecord['is_estimated']);

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
        self::assertSame(1, (int) self::$database?->query('SELECT COUNT(*) FROM ai_usage_records WHERE is_estimated = 1')->fetchColumn());
    }

    public function testInstallationChatReservationUsesOnlyGlobalBuckets(): void
    {
        $repository = $this->repository();
        $limits = [
            'global_daily' => 1_000,
            'global_monthly' => 5_000,
            'api_key_daily' => 1,
            'api_key_monthly' => 1,
        ];
        $now = new DateTimeImmutable('2026-07-20 12:00:00', new DateTimeZone('UTC'));
        $reservation = $repository->reserve(0, 'chat', 100, $limits, $now, $now->modify('+15 minutes'));
        $repository->reconcile($reservation->id, 25);
        $usage = $repository->usage([], $now);

        self::assertSame(25, $usage['global']['daily']['consumed']);
        self::assertSame(0, $repository->usageAcrossApiKeys($now)['daily']['consumed']);
        self::assertSame(
            0,
            (int) self::$database?->query("SELECT COUNT(*) FROM provider_quota_buckets WHERE scope = 'api_key'")->fetchColumn(),
        );
    }

    public function testApiKeyUsageTotalIncludesEveryConnection(): void
    {
        $repository = $this->repository();
        $limits = [
            'global_daily' => 1_000,
            'global_monthly' => 5_000,
            'api_key_daily' => 500,
            'api_key_monthly' => 2_500,
        ];
        $now = new DateTimeImmutable('2026-07-20 12:00:00', new DateTimeZone('UTC'));
        $first = $repository->reserve(7, 'retrieve', 100, $limits, $now, $now->modify('+15 minutes'));
        $second = $repository->reserve(8, 'retrieve', 100, $limits, $now, $now->modify('+15 minutes'));
        $repository->reconcile($first->id, 12);
        $repository->reconcile($second->id, 18);

        $usage = $repository->usageAcrossApiKeys($now);

        self::assertSame(30, $usage['daily']['consumed']);
        self::assertSame(30, $usage['monthly']['consumed']);
        self::assertSame(0, $usage['daily']['reserved']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::$database?->exec('DELETE FROM provider_quota_reservations');
        self::$database?->exec('DELETE FROM provider_quota_buckets');
        self::$database?->exec('DELETE FROM ai_usage_records');
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
