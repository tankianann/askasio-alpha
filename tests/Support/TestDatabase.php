<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Database\Migrator;
use PDO;
use RuntimeException;

final class TestDatabase
{
    public static function isConfigured(): bool
    {
        return self::environment('TEST_DB_DATABASE') !== null;
    }

    public static function recreate(): PDO
    {
        $database = self::databaseName();
        $server = self::serverConnection();
        $quotedDatabase = sprintf('`%s`', $database);

        $server->exec(sprintf('DROP DATABASE IF EXISTS %s', $quotedDatabase));
        $server->exec(sprintf(
            'CREATE DATABASE %s CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            $quotedDatabase,
        ));

        $pdo = self::databaseConnection();
        $pdo->exec("SET time_zone = '+00:00'");

        return $pdo;
    }

    /** @return list<string> */
    public static function migrate(PDO $pdo): array
    {
        return (new Migrator($pdo, dirname(__DIR__, 2) . '/database/migrations'))->migrate();
    }

    public static function drop(): void
    {
        $database = self::databaseName();
        self::serverConnection()->exec(sprintf('DROP DATABASE IF EXISTS `%s`', $database));
    }

    /** @return array<string, string> */
    public static function applicationEnvironment(): array
    {
        return [
            'APP_ENV' => 'testing',
            'APP_DEBUG' => 'false',
            'APP_URL' => 'http://localhost',
            'APP_SECRET' => 'test-only-application-secret-at-least-32-characters',
            'APP_TIMEZONE' => 'UTC',
            'SESSION_SECURE_COOKIE' => 'never',
            'DB_HOST' => self::environment('TEST_DB_HOST', '127.0.0.1'),
            'DB_PORT' => self::environment('TEST_DB_PORT', '3306'),
            'DB_DATABASE' => self::databaseName(),
            'DB_USERNAME' => self::environment('TEST_DB_USERNAME', 'root'),
            'DB_PASSWORD' => self::environment('TEST_DB_PASSWORD', ''),
        ];
    }

    private static function serverConnection(): PDO
    {
        return self::connect(sprintf(
            'mysql:host=%s;port=%s;charset=utf8mb4',
            self::environment('TEST_DB_HOST', '127.0.0.1'),
            self::environment('TEST_DB_PORT', '3306'),
        ));
    }

    private static function databaseConnection(): PDO
    {
        return self::connect(sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            self::environment('TEST_DB_HOST', '127.0.0.1'),
            self::environment('TEST_DB_PORT', '3306'),
            self::databaseName(),
        ));
    }

    private static function connect(string $dsn): PDO
    {
        return new PDO(
            $dsn,
            self::environment('TEST_DB_USERNAME', 'root'),
            self::environment('TEST_DB_PASSWORD', ''),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ],
        );
    }

    private static function databaseName(): string
    {
        $database = self::environment('TEST_DB_DATABASE');

        if ($database === null) {
            throw new RuntimeException('TEST_DB_DATABASE is required for database integration tests.');
        }

        if (preg_match('/\A[A-Za-z0-9_]+_test\z/', $database) !== 1) {
            throw new RuntimeException('TEST_DB_DATABASE must contain only letters, numbers, and underscores and end in _test.');
        }

        return $database;
    }

    private static function environment(string $key, ?string $default = null): ?string
    {
        $value = getenv($key);

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
