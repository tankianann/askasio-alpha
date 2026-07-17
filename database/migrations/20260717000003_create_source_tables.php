<?php

declare(strict_types=1);

use App\Database\Migration;

return new class implements Migration {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
            CREATE TABLE sources (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(190) NOT NULL,
                source_type VARCHAR(20) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'enabled',
                active_version_id BIGINT UNSIGNED NULL,
                created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
                deleted_at DATETIME(6) NULL,
                KEY idx_sources_status_deleted (status, deleted_at),
                KEY idx_sources_type_deleted (source_type, deleted_at),
                CONSTRAINT chk_sources_type CHECK (source_type IN ('url', 'markdown', 'pdf')),
                CONSTRAINT chk_sources_status CHECK (status IN ('enabled', 'disabled'))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        $pdo->exec(<<<'SQL'
            CREATE TABLE source_versions (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                source_id BIGINT UNSIGNED NOT NULL,
                version_number INT UNSIGNED NOT NULL,
                original_filename VARCHAR(255) NULL,
                original_url TEXT NULL,
                stored_file_path VARCHAR(500) NULL,
                content_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
                mime_type VARCHAR(190) NULL,
                file_size BIGINT UNSIGNED NULL,
                processing_status VARCHAR(20) NOT NULL DEFAULT 'pending',
                error_message TEXT NULL,
                extracted_text LONGTEXT NULL,
                metadata_json JSON NULL,
                created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                processed_at DATETIME(6) NULL,
                activated_at DATETIME(6) NULL,
                UNIQUE KEY uq_source_versions_number (source_id, version_number),
                KEY idx_source_versions_status (processing_status),
                KEY idx_source_versions_hash (content_hash),
                CONSTRAINT fk_source_versions_source
                    FOREIGN KEY (source_id) REFERENCES sources (id) ON DELETE CASCADE,
                CONSTRAINT chk_source_versions_status
                    CHECK (processing_status IN ('pending', 'processing', 'ready', 'failed', 'inactive'))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        $pdo->exec(<<<'SQL'
            ALTER TABLE sources
                ADD CONSTRAINT fk_sources_active_version
                FOREIGN KEY (active_version_id) REFERENCES source_versions (id) ON DELETE SET NULL
            SQL);
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE sources DROP FOREIGN KEY fk_sources_active_version');
        $pdo->exec('DROP TABLE source_versions');
        $pdo->exec('DROP TABLE sources');
    }
};
