<?php

declare(strict_types=1);

namespace App\Auth;

interface SessionStoreInterface
{
    public function start(bool $requestIsSecure): void;

    public function get(string $key, mixed $default = null): mixed;

    public function put(string $key, mixed $value): void;

    public function remove(string $key): void;

    public function pull(string $key, mixed $default = null): mixed;

    public function regenerate(): void;

    public function invalidate(): void;
}
