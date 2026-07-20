<?php

declare(strict_types=1);

namespace App\Auth;

use RuntimeException;

final class NativeSessionStore implements SessionStoreInterface
{
    private bool $started = false;

    public function __construct(
        private readonly string $name,
        private readonly int $idleTimeoutSeconds,
        private readonly int $regenerationIntervalSeconds,
        private readonly string $secureCookieMode,
    ) {
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $this->name) !== 1) {
            throw new RuntimeException('SESSION_NAME contains invalid characters.');
        }

        if ($this->idleTimeoutSeconds < 300) {
            throw new RuntimeException('SESSION_IDLE_MINUTES must be at least 5.');
        }

        if ($this->regenerationIntervalSeconds < 60 || $this->regenerationIntervalSeconds > $this->idleTimeoutSeconds) {
            throw new RuntimeException('The session regeneration interval must be between one minute and the idle timeout.');
        }

        if (!in_array($this->secureCookieMode, ['auto', 'always', 'never'], true)) {
            throw new RuntimeException('SESSION_SECURE_COOKIE must be auto, always, or never.');
        }
    }

    public function start(bool $requestIsSecure): void
    {
        if ($this->started) {
            return;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            $this->started = true;
            return;
        }

        $secure = $this->secureCookieMode === 'always'
            || ($this->secureCookieMode === 'auto' && $requestIsSecure);

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Lax');
        ini_set('session.gc_maxlifetime', (string) $this->idleTimeoutSeconds);
        session_name($this->name);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        if (!session_start()) {
            throw new RuntimeException('The administrator session could not be started.');
        }

        $this->started = true;
        $now = time();
        $lastActivity = $_SESSION['_last_activity'] ?? null;

        if (is_int($lastActivity) && $lastActivity < $now - $this->idleTimeoutSeconds) {
            $_SESSION = [];
            session_regenerate_id(true);
        }

        $lastRegenerated = $_SESSION['_last_regenerated'] ?? null;

        if (!is_int($lastRegenerated) || $lastRegenerated < $now - $this->regenerationIntervalSeconds) {
            session_regenerate_id(true);
            $_SESSION['_last_regenerated'] = $now;
        }

        $_SESSION['_last_activity'] = $now;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function put(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);
        $this->remove($key);

        return $value;
    }

    public function regenerate(): void
    {
        session_regenerate_id(true);
        $_SESSION['_last_regenerated'] = time();
    }

    public function invalidate(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $parameters = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $parameters['path'],
                'domain' => $parameters['domain'],
                'secure' => $parameters['secure'],
                'httponly' => $parameters['httponly'],
                'samesite' => $parameters['samesite'],
            ]);
        }

        session_destroy();
        $this->started = false;
    }
}
