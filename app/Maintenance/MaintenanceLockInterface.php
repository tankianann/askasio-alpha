<?php

declare(strict_types=1);

namespace App\Maintenance;

interface MaintenanceLockInterface
{
    public function acquire(string $name): bool;

    public function release(string $name): void;
}
