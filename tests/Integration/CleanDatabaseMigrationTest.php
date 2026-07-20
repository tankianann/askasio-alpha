<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Database\Migrator;
use PDO;
use Tests\Support\DatabaseIntegrationTestCase;

final class CleanDatabaseMigrationTest extends DatabaseIntegrationTestCase
{
    public function testEveryMigrationAppliesToAnEmptyDatabaseAndIsIdempotent(): void
    {
        $migrationFiles = glob(dirname(__DIR__, 2) . '/database/migrations/*.php');
        self::assertIsArray($migrationFiles);

        $applied = self::$database?->query(
            'SELECT migration FROM schema_migrations ORDER BY migration',
        )->fetchAll(PDO::FETCH_COLUMN);

        self::assertIsArray($applied);
        self::assertCount(count($migrationFiles), $applied);
        self::assertSame(
            array_map(static fn (string $file): string => basename($file, '.php'), $migrationFiles),
            array_map('strval', $applied),
        );

        $tables = self::$database?->query(
            "SELECT table_name FROM information_schema.tables
             WHERE table_schema = DATABASE() ORDER BY table_name",
        )->fetchAll(PDO::FETCH_COLUMN);

        self::assertEqualsCanonicalizing([
            'admin_login_attempts',
            'admin_users',
            'api_keys',
            'api_rate_limit_buckets',
            'api_request_logs',
            'chatbot_drafts',
            'chatbot_draft_origins',
            'chatbot_draft_sources',
            'chatbot_publications',
            'chatbot_publication_origins',
            'chatbot_publication_sources',
            'chatbots',
            'ingestion_jobs',
            'provider_quota_buckets',
            'provider_quota_reservations',
            'schema_migrations',
            'settings',
            'source_chunks',
            'source_versions',
            'sources',
        ], $tables);

        $migrator = new Migrator(self::$database, dirname(__DIR__, 2) . '/database/migrations');
        self::assertSame([], $migrator->migrate(), 'A second migration pass must not alter the schema.');
    }
}
