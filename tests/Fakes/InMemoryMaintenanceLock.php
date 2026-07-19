<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Maintenance\MaintenanceLockInterface;

final class InMemoryMaintenanceLock implements MaintenanceLockInterface
{
    public bool $available = true;
    public bool $held = false;
    public int $acquisitionAttempts = 0;
    public int $releaseCount = 0;

    public function acquire(string $name): bool
    {
        ++$this->acquisitionAttempts;

        if (!$this->available || $this->held) {
            return false;
        }

        $this->held = true;

        return true;
    }

    public function release(string $name): void
    {
        if (!$this->held) {
            throw new \LogicException('The in-memory maintenance lock is not held.');
        }

        $this->held = false;
        ++$this->releaseCount;
    }
}
