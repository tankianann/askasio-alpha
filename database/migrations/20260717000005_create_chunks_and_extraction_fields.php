<?php

declare(strict_types=1);

use App\Database\Migration;

return new class implements Migration {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
            ALTER TABLE source_versions
                ADD COLUMN file_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER content_hash
            SQL);

        $pdo->exec(<<<'SQL'
            UPDATE source_versions
            SET file_hash = content_hash, content_hash = NULL
            WHERE stored_file_path IS NOT NULL AND content_hash IS NOT NULL
            SQL);

        $pdo->exec(<<<'SQL'
            CREATE TABLE source_chunks (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                source_version_id BIGINT UNSIGNED NOT NULL,
                chunk_number INT UNSIGNED NOT NULL,
                content LONGTEXT NOT NULL,
                token_count INT UNSIGNED NOT NULL,
                embedding JSON NULL,
                metadata_json JSON NOT NULL,
                created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                UNIQUE KEY uq_source_chunks_version_number (source_version_id, chunk_number),
                KEY idx_source_chunks_version (source_version_id),
                CONSTRAINT fk_source_chunks_version
                    FOREIGN KEY (source_version_id) REFERENCES source_versions (id) ON DELETE CASCADE,
                CONSTRAINT chk_source_chunks_token_count CHECK (token_count > 0)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE source_chunks');
        $pdo->exec('ALTER TABLE source_versions DROP COLUMN file_hash');
    }
};
