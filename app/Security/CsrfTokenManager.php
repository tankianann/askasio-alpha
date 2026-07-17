<?php

declare(strict_types=1);

namespace App\Security;

use App\Auth\SessionStoreInterface;

final class CsrfTokenManager
{
    private const SESSION_KEY = '_csrf_token';

    public function __construct(private readonly SessionStoreInterface $session)
    {
    }

    public function token(): string
    {
        $token = $this->session->get(self::SESSION_KEY);

        if (!is_string($token) || strlen($token) !== 64) {
            $token = bin2hex(random_bytes(32));
            $this->session->put(self::SESSION_KEY, $token);
        }

        return $token;
    }

    public function validate(mixed $submittedToken): bool
    {
        $storedToken = $this->session->get(self::SESSION_KEY);

        return is_string($storedToken)
            && is_string($submittedToken)
            && strlen($storedToken) === 64
            && hash_equals($storedToken, $submittedToken);
    }

    public function rotate(): string
    {
        $token = bin2hex(random_bytes(32));
        $this->session->put(self::SESSION_KEY, $token);

        return $token;
    }
}
