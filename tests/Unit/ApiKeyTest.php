<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\ApiKeys\ApiKey;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class ApiKeyTest extends TestCase
{
    public function testExpiredAndRevokedKeysAreNotUsable(): void
    {
        $now = new DateTimeImmutable('2026-07-17 12:00:00', new DateTimeZone('UTC'));
        $expired = $this->key('active', '2026-07-17 11:59:59', null);
        $revoked = $this->key('revoked', null, '2026-07-17 11:00:00');

        self::assertTrue($expired->isExpired($now));
        self::assertFalse($expired->isUsable($now));
        self::assertFalse($revoked->isUsable($now));
    }

    public function testActiveUnexpiredKeyIsUsable(): void
    {
        $now = new DateTimeImmutable('2026-07-17 12:00:00', new DateTimeZone('UTC'));
        self::assertTrue($this->key('active', '2026-07-18 12:00:00', null)->isUsable($now));
    }

    private function key(string $status, ?string $expiresAt, ?string $revokedAt): ApiKey
    {
        return new ApiKey(
            1, 1, 'Client', 'rag_live_abcdefgh', str_repeat('a', 64), $status,
            '2026-01-01 00:00:00', null, $expiresAt, $revokedAt,
        );
    }
}
