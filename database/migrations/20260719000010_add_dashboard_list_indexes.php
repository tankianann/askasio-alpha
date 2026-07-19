<?php

declare(strict_types=1);

use App\Database\Migration;

return new class implements Migration {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
            ALTER TABLE sources
                ADD INDEX idx_sources_updated_id (updated_at, id),
                ADD INDEX idx_sources_name_id (name, id)
            SQL);
        $pdo->exec(<<<'SQL'
            ALTER TABLE ingestion_jobs
                ADD INDEX idx_ingestion_jobs_created_id (created_at, id),
                ADD INDEX idx_ingestion_jobs_status_created (status, created_at, id),
                ADD INDEX idx_ingestion_jobs_attempts_created (attempts, created_at, id)
            SQL);
        $pdo->exec(<<<'SQL'
            ALTER TABLE api_keys
                ADD INDEX idx_api_keys_created_id (created_at, id),
                ADD INDEX idx_api_keys_name_id (name, id),
                ADD INDEX idx_api_keys_last_used_id (last_used_at, id)
            SQL);
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
            ALTER TABLE api_keys
                DROP INDEX idx_api_keys_created_id,
                DROP INDEX idx_api_keys_name_id,
                DROP INDEX idx_api_keys_last_used_id
            SQL);
        $pdo->exec(<<<'SQL'
            ALTER TABLE ingestion_jobs
                DROP INDEX idx_ingestion_jobs_created_id,
                DROP INDEX idx_ingestion_jobs_status_created,
                DROP INDEX idx_ingestion_jobs_attempts_created
            SQL);
        $pdo->exec(<<<'SQL'
            ALTER TABLE sources
                DROP INDEX idx_sources_updated_id,
                DROP INDEX idx_sources_name_id
            SQL);
    }
};
