<?php

declare(strict_types=1);

namespace App\Auth;

use App\Domain\Admin\AdminUser;
use App\Repositories\AdminRepositoryInterface;

final class AuthenticationService
{
    private const DUMMY_PASSWORD_HASH = '$argon2id$v=19$m=65536,t=4,p=1$xUsLwdHTtiabeXJe4O5x8w$PDX6GT7n9qx80TCHctL5V79CBuUU4EbMvYlNrfkGFWQ';

    public function __construct(
        private readonly AdminRepositoryInterface $admins,
        private readonly PasswordHasher $passwordHasher,
    ) {
    }

    public function authenticate(string $username, string $password): ?AdminUser
    {
        $normalizedUsername = self::normalizeUsername($username);
        $admin = $this->admins->findByUsername($normalizedUsername);
        $hash = $admin?->passwordHash ?? self::DUMMY_PASSWORD_HASH;

        if (!$this->passwordHasher->verify($password, $hash) || !$admin instanceof AdminUser) {
            return null;
        }

        if ($this->passwordHasher->needsRehash($admin->passwordHash)) {
            $this->admins->updatePasswordHash($admin->id, $this->passwordHasher->hash($password));
        }

        $this->admins->recordSuccessfulLogin($admin->id);

        return $admin;
    }

    public static function normalizeUsername(string $username): string
    {
        return strtolower(trim($username));
    }
}
