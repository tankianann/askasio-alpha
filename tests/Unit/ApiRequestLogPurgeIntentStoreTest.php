<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Api\ApiRequestLogPurgeCriteria;
use App\Domain\Api\ApiRequestLogPurgeScope;
use App\Domain\Api\ApiRequestLogPurgeSnapshot;
use App\Exceptions\ValidationException;
use App\Services\Api\ApiRequestLogPurgeIntentStore;
use PHPUnit\Framework\TestCase;
use Tests\Fakes\InMemorySessionStore;

final class ApiRequestLogPurgeIntentStoreTest extends TestCase
{
    public function testItStoresAValidatedServerSideIntentAndConfirmationPhrase(): void
    {
        $store = new ApiRequestLogPurgeIntentStore(new InMemorySessionStore());
        $created = $store->create(new ApiRequestLogPurgeSnapshot(
            new ApiRequestLogPurgeCriteria(ApiRequestLogPurgeScope::All),
            12,
            44,
        ));
        $loaded = $store->require($created->token);

        self::assertSame(64, strlen($created->token));
        self::assertSame(12, $loaded->snapshot->recordCount);
        self::assertSame(44, $loaded->snapshot->maximumId);
        self::assertSame('DELETE 12 REQUESTS', $loaded->confirmationPhrase());
    }

    public function testItRejectsAClientSuppliedTokenThatDoesNotMatchTheSession(): void
    {
        $store = new ApiRequestLogPurgeIntentStore(new InMemorySessionStore());
        $store->create(new ApiRequestLogPurgeSnapshot(
            new ApiRequestLogPurgeCriteria(ApiRequestLogPurgeScope::All),
            1,
            1,
        ));

        $this->expectException(ValidationException::class);
        $store->require(str_repeat('0', 64));
    }

    public function testItRejectsAnExpiredIntent(): void
    {
        $now = 1000;
        $store = new ApiRequestLogPurgeIntentStore(
            new InMemorySessionStore(),
            60,
            static function () use (&$now): int {
                return $now;
            },
        );
        $intent = $store->create(new ApiRequestLogPurgeSnapshot(
            new ApiRequestLogPurgeCriteria(ApiRequestLogPurgeScope::All),
            1,
            1,
        ));
        $now += 61;

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('expired');
        $store->require($intent->token);
    }
}
