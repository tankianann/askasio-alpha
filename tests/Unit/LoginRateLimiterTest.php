<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Auth\LoginRateLimiter;
use PHPUnit\Framework\TestCase;
use Tests\Fakes\InMemoryLoginAttemptRepository;

final class LoginRateLimiterTest extends TestCase
{
    public function testItLimitsByNormalizedUsernameAndIpWithoutStoringEither(): void
    {
        $attempts = new InMemoryLoginAttemptRepository();
        $limiter = new LoginRateLimiter($attempts, str_repeat('s', 32), 5, 900);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $limiter->recordFailure('Administrator', '192.0.2.10');
        }

        self::assertTrue($limiter->tooManyAttempts('administrator', '198.51.100.2'));
        self::assertTrue($limiter->tooManyAttempts('different-user', '192.0.2.10'));
        self::assertFalse($limiter->tooManyAttempts('different-user', '198.51.100.2'));
        self::assertSame(15, $limiter->windowMinutes());

        $limiter->clear('administrator', '192.0.2.10');
        self::assertFalse($limiter->tooManyAttempts('administrator', '192.0.2.10'));
    }
}
