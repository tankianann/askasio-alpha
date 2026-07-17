<?php

declare(strict_types=1);

use App\Database\Migration;

return new class implements Migration {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
            CREATE TABLE ingestion_jobs (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                source_version_id BIGINT UNSIGNED NOT NULL,
                job_type VARCHAR(50) NOT NULL DEFAULT 'ingest_source_version',
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                priority SMALLINT NOT NULL DEFAULT 0,
                attempts INT UNSIGNED NOT NULL DEFAULT 0,
                max_attempts INT UNSIGNED NOT NULL DEFAULT 3,
                available_at DATETIME(6) NOT NULL,
                reserved_at DATETIME(6) NULL,
                reserved_by VARCHAR(64) NULL,
                last_error VARCHAR(1000) NULL,
                created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
                completed_at DATETIME(6) NULL,
                failed_at DATETIME(6) NULL,
                KEY idx_ingestion_jobs_claim (status, available_at, priority, id),
                KEY idx_ingestion_jobs_reservation (status, reserved_at),
                KEY idx_ingestion_jobs_version (source_version_id, status),
                CONSTRAINT fk_ingestion_jobs_source_version
                    FOREIGN KEY (source_version_id) REFERENCES source_versions (id) ON DELETE CASCADE,
                CONSTRAINT chk_ingestion_jobs_status
                    CHECK (status IN ('pending', 'processing', 'completed', 'failed')),
                CONSTRAINT chk_ingestion_jobs_attempts
                    CHECK (max_attempts > 0 AND attempts <= max_attempts)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        $pdo->exec(<<<'SQL'
            INSERT INTO ingestion_jobs (
                source_version_id, job_type, status, priority, attempts, max_attempts,
                available_at, created_at, updated_at
            )
            SELECT sv.id, 'ingest_source_version', 'pending', 0, 0, 3,
                   UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), UTC_TIMESTAMP(6)
            FROM source_versions sv
            WHERE sv.processing_status = 'pending'
              AND NOT EXISTS (
                  SELECT 1 FROM ingestion_jobs ij WHERE ij.source_version_id = sv.id
              )
            SQL);
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE ingestion_jobs');
    }
};
