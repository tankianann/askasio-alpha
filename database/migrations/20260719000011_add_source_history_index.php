<?php

declare(strict_types=1);

use App\Database\Migration;

return new class implements Migration {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
            ALTER TABLE ingestion_jobs
                ADD INDEX idx_ingestion_jobs_version_created (source_version_id, created_at, id)
            SQL);
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE ingestion_jobs DROP INDEX idx_ingestion_jobs_version_created');
    }
};
