<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\ApiKeys\ApiKey;
use App\Services\Api\ApiRateLimiter;
use PHPUnit\Framework\TestCase;
use Tests\Fakes\InMemoryApiRateLimitRepository;

final class ApiRateLimiterTest extends TestCase
{
    public function testItLimitsRequestsPerKeyWithinFixedWindow(): void
    {
        $limiter = new ApiRateLimiter(
            new InMemoryApiRateLimitRepository(),
            str_repeat('s', 32),
            60,
            2,
            10,
        );
        $key = $this->key();

        self::assertTrue($limiter->consume($key, '203.0.113.10', 120)->allowed);
        self::assertTrue($limiter->consume($key, '203.0.113.10', 121)->allowed);
        $denied = $limiter->consume($key, '203.0.113.10', 122);
        self::assertFalse($denied->allowed);
        self::assertSame(58, $denied->retryAfterSeconds);
    }

    public function testAChangedWindowResetsCounts(): void
    {
        $limiter = new ApiRateLimiter(
            new InMemoryApiRateLimitRepository(),
            str_repeat('s', 32),
            60,
            1,
            10,
        );
        $key = $this->key();

        self::assertTrue($limiter->consume($key, '203.0.113.10', 119)->allowed);
        self::assertTrue($limiter->consume($key, '203.0.113.10', 120)->allowed);
    }

    public function testNamespacesKeepChatAndGeneralApiBucketsIndependent(): void
    {
        $repository = new InMemoryApiRateLimitRepository();
        $general = new ApiRateLimiter($repository, str_repeat('s', 32), 60, 1, 10, 'api');
        $chat = new ApiRateLimiter($repository, str_repeat('s', 32), 60, 1, 10, 'chat');
        $key = $this->key();

        self::assertTrue($general->consume($key, '203.0.113.10', 120)->allowed);
        self::assertTrue($chat->consume($key, '203.0.113.10', 120)->allowed);
        self::assertFalse($general->consume($key, '203.0.113.10', 121)->allowed);
        self::assertFalse($chat->consume($key, '203.0.113.10', 121)->allowed);
    }

    private function key(): ApiKey
    {
        return new ApiKey(
            1, 1, 'Client', 'rag_live_abcdefgh', str_repeat('a', 64), 'active',
            '2026-01-01 00:00:00', null, null, null,
        );
    }
}
