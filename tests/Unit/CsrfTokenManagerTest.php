<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Security\CsrfTokenManager;
use PHPUnit\Framework\TestCase;
use Tests\Fakes\InMemorySessionStore;

final class CsrfTokenManagerTest extends TestCase
{
    public function testTokenIsStoredAndValidatedWithConstantTimeComparison(): void
    {
        $tokens = new CsrfTokenManager(new InMemorySessionStore());
        $token = $tokens->token();

        self::assertSame(64, strlen($token));
        self::assertSame($token, $tokens->token());
        self::assertTrue($tokens->validate($token));
        self::assertFalse($tokens->validate(str_repeat('0', 64)));
        self::assertFalse($tokens->validate(null));
    }

    public function testRotatingTokenInvalidatesPreviousToken(): void
    {
        $tokens = new CsrfTokenManager(new InMemorySessionStore());
        $oldToken = $tokens->token();
        $newToken = $tokens->rotate();

        self::assertNotSame($oldToken, $newToken);
        self::assertFalse($tokens->validate($oldToken));
        self::assertTrue($tokens->validate($newToken));
    }
}
