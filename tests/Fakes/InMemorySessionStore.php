<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Auth\SessionStoreInterface;

final class InMemorySessionStore implements SessionStoreInterface
{
    /** @var array<string, mixed> */
    private array $values = [];

    public bool $regenerated = false;

    public bool $invalidated = false;

    public function start(bool $requestIsSecure): void
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function put(string $key, mixed $value): void
    {
        $this->values[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($this->values[$key]);
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);
        $this->remove($key);

        return $value;
    }

    public function regenerate(): void
    {
        $this->regenerated = true;
    }

    public function invalidate(): void
    {
        $this->values = [];
        $this->invalidated = true;
    }
}
