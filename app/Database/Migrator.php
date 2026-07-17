<?php

declare(strict_types=1);

namespace App\Database;

use PDO;
use RuntimeException;
use Throwable;

final class Migrator
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $migrationDirectory,
    ) {
    }

    /** @return list<string> */
    public function migrate(): array
    {
        $this->ensureMigrationsTable();
        $applied = $this->appliedMigrations();
        $executed = [];
        $batch = $this->nextBatchNumber();

        foreach ($this->migrationFiles() as $file) {
            $name = basename($file, '.php');

            if (isset($applied[$name])) {
                continue;
            }

            $migration = require $file;

            if (!$migration instanceof Migration) {
                throw new RuntimeException(sprintf('Migration %s must return an instance of %s.', $name, Migration::class));
            }

            try {
                $this->pdo->beginTransaction();
                $migration->up($this->pdo);

                $statement = $this->pdo->prepare(
                    'INSERT INTO schema_migrations (migration, batch, applied_at) VALUES (:migration, :batch, UTC_TIMESTAMP(6))',
                );
                $statement->execute([
                    'migration' => $name,
                    'batch' => $batch,
                ]);

                if ($this->pdo->inTransaction()) {
                    $this->pdo->commit();
                }
            } catch (Throwable $exception) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }

                throw $exception;
            }

            $executed[] = $name;
        }

        return $executed;
    }

    private function ensureMigrationsTable(): void
    {
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS schema_migrations (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                migration VARCHAR(190) NOT NULL UNIQUE,
                batch INT UNSIGNED NOT NULL,
                applied_at DATETIME(6) NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }

    /** @return array<string, true> */
    private function appliedMigrations(): array
    {
        $rows = $this->pdo->query('SELECT migration FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);

        return array_fill_keys(array_map('strval', $rows), true);
    }

    private function nextBatchNumber(): int
    {
        $batch = $this->pdo->query('SELECT COALESCE(MAX(batch), 0) + 1 FROM schema_migrations')->fetchColumn();

        return (int) $batch;
    }

    /** @return list<string> */
    private function migrationFiles(): array
    {
        $files = glob(rtrim($this->migrationDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.php');

        if ($files === false) {
            throw new RuntimeException('Unable to read migration directory.');
        }

        sort($files);

        return array_values($files);
    }
}
