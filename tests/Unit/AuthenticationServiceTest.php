<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Auth\AuthenticationService;
use App\Auth\PasswordHasher;
use PHPUnit\Framework\TestCase;
use Tests\Fakes\InMemoryAdminRepository;

final class AuthenticationServiceTest extends TestCase
{
    public function testItAuthenticatesNormalizedUsernameAndRecordsLogin(): void
    {
        $admins = new InMemoryAdminRepository();
        $hasher = new PasswordHasher();
        $admins->create('administrator', $hasher->hash('correct horse battery staple'));
        $service = new AuthenticationService($admins, $hasher);

        $admin = $service->authenticate('  ADMINISTRATOR ', 'correct horse battery staple');

        self::assertNotNull($admin);
        self::assertSame('administrator', $admin->username);
        self::assertSame(1, $admins->successfulLogins);
    }

    public function testItReturnsNullForUnknownUserOrWrongPassword(): void
    {
        $admins = new InMemoryAdminRepository();
        $hasher = new PasswordHasher();
        $admins->create('administrator', $hasher->hash('correct horse battery staple'));
        $service = new AuthenticationService($admins, $hasher);

        self::assertNull($service->authenticate('missing', 'correct horse battery staple'));
        self::assertNull($service->authenticate('administrator', 'wrong password'));
        self::assertSame(0, $admins->successfulLogins);
    }
}
