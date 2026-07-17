<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Auth\PasswordHasher;
use PHPUnit\Framework\TestCase;

final class PasswordHasherTest extends TestCase
{
    public function testItHashesAndVerifiesPasswordsSecurely(): void
    {
        $hasher = new PasswordHasher();
        $hash = $hasher->hash('correct horse battery staple');

        self::assertNotSame('correct horse battery staple', $hash);
        self::assertTrue($hasher->verify('correct horse battery staple', $hash));
        self::assertFalse($hasher->verify('incorrect password', $hash));

        if (defined('PASSWORD_ARGON2ID')) {
            self::assertSame('argon2id', password_get_info($hash)['algoName']);
        }
    }
}
