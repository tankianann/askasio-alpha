<?php

declare(strict_types=1);

namespace App\Domain\Admin;

final class AdminUser
{
    public function __construct(
        public readonly int $id,
        public readonly string $username,
        public readonly string $passwordHash,
        public readonly ?string $lastLoginAt = null,
    ) {
    }
}
