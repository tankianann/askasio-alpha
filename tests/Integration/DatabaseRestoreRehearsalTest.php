<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use Tests\Support\DatabaseIntegrationTestCase;
use Tests\Support\TestDatabase;

final class DatabaseRestoreRehearsalTest extends DatabaseIntegrationTestCase
{
    public function testSchemaLedgerAndDataCanBeRestoredIntoAnIsolatedDatabase(): void
    {
        $environment = TestDatabase::applicationEnvironment();
        $source = $environment['DB_DATABASE'];
        $target = preg_replace('/_test\z/', '_restored_test', $source);
        self::assertIsString($target);
        self::assertMatchesRegularExpression('/\A[A-Za-z0-9_]+_restored_test\z/', $target);
        $server = new PDO(
            sprintf(
                'mysql:host=%s;port=%s;charset=utf8mb4',
                $environment['DB_HOST'],
                $environment['DB_PORT'],
            ),
            $environment['DB_USERNAME'],
            $environment['DB_PASSWORD'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
        );
        $quotedSource = $this->quoteIdentifier($source);
        $quotedTarget = $this->quoteIdentifier($target);
        self::$database?->prepare(
            'INSERT INTO settings (setting_key, setting_value, value_type) VALUES (:key, :value, :type)',
        )->execute([
            'key' => 'release_rehearsal_marker',
            'value' => 'restore-me',
            'type' => 'string',
        ]);

        try {
            $server->exec('DROP DATABASE IF EXISTS ' . $quotedTarget);
            $server->exec(sprintf(
                'CREATE DATABASE %s CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
                $quotedTarget,
            ));
            $server->exec('SET FOREIGN_KEY_CHECKS = 0');
            $tables = self::$database?->query(
                'SELECT table_name FROM information_schema.tables
                 WHERE table_schema = DATABASE() ORDER BY table_name',
            )->fetchAll(PDO::FETCH_COLUMN);
            self::assertIsArray($tables);

            foreach ($tables as $table) {
                self::assertIsString($table);
                $quotedTable = $this->quoteIdentifier($table);
                $definition = $server->query(
                    'SHOW CREATE TABLE ' . $quotedSource . '.' . $quotedTable,
                )->fetch();
                self::assertIsArray($definition);
                $create = $definition['Create Table'] ?? null;
                self::assertIsString($create);
                $qualified = preg_replace(
                    '/\ACREATE TABLE ' . preg_quote($quotedTable, '/') . '/',
                    'CREATE TABLE ' . $quotedTarget . '.' . $quotedTable,
                    $create,
                    1,
                );
                self::assertIsString($qualified);
                $server->exec($qualified);
                $server->exec(sprintf(
                    'INSERT INTO %s.%s SELECT * FROM %s.%s',
                    $quotedTarget,
                    $quotedTable,
                    $quotedSource,
                    $quotedTable,
                ));
            }

            $server->exec('SET FOREIGN_KEY_CHECKS = 1');
            $restored = new PDO(
                sprintf(
                    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                    $environment['DB_HOST'],
                    $environment['DB_PORT'],
                    $target,
                ),
                $environment['DB_USERNAME'],
                $environment['DB_PASSWORD'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false],
            );
            self::assertSame(
                (int) self::$database?->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn(),
                (int) $restored->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn(),
            );
            self::assertSame(
                'restore-me',
                $restored->query(
                    "SELECT setting_value FROM settings WHERE setting_key = 'release_rehearsal_marker'",
                )->fetchColumn(),
            );
            self::assertSame(count($tables), (int) $restored->query(
                'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()',
            )->fetchColumn());
        } finally {
            $server->exec('SET FOREIGN_KEY_CHECKS = 1');
            $server->exec('DROP DATABASE IF EXISTS ' . $quotedTarget);
        }
    }

    private function quoteIdentifier(string $identifier): string
    {
        if (preg_match('/\A[A-Za-z0-9_]+\z/', $identifier) !== 1) {
            throw new \InvalidArgumentException('The restore rehearsal identifier is invalid.');
        }

        return '`' . $identifier . '`';
    }
}
