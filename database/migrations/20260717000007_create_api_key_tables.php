<?php

declare(strict_types=1);

use App\Database\Migration;

return new class implements Migration {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
            CREATE TABLE api_keys (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                created_by_admin_id BIGINT UNSIGNED NOT NULL,
                name VARCHAR(190) NOT NULL,
                visible_prefix VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                secret_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'active',
                created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                last_used_at DATETIME(6) NULL,
                expires_at DATETIME(6) NULL,
                revoked_at DATETIME(6) NULL,
                UNIQUE KEY uq_api_keys_secret_hash (secret_hash),
                KEY idx_api_keys_status_expiry (status, expires_at),
                KEY idx_api_keys_admin (created_by_admin_id),
                CONSTRAINT fk_api_keys_admin
                    FOREIGN KEY (created_by_admin_id) REFERENCES admin_users (id) ON DELETE RESTRICT,
                CONSTRAINT chk_api_keys_status CHECK (status IN ('active', 'revoked'))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        $pdo->exec(<<<'SQL'
            CREATE TABLE api_rate_limit_buckets (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                scope VARCHAR(20) NOT NULL,
                identifier_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                bucket_started_at DATETIME NOT NULL,
                expires_at DATETIME NOT NULL,
                request_count INT UNSIGNED NOT NULL DEFAULT 1,
                created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
                UNIQUE KEY uq_api_rate_bucket (scope, identifier_hash, bucket_started_at),
                KEY idx_api_rate_expiry (expires_at),
                CONSTRAINT chk_api_rate_scope CHECK (scope IN ('api_key', 'ip')),
                CONSTRAINT chk_api_rate_count CHECK (request_count > 0)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        $pdo->exec(<<<'SQL'
            CREATE TABLE api_request_logs (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                request_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                api_key_id BIGINT UNSIGNED NULL,
                ip_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
                method VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                endpoint VARCHAR(255) NOT NULL,
                status_code SMALLINT UNSIGNED NOT NULL,
                duration_ms INT UNSIGNED NOT NULL,
                error_category VARCHAR(100) NULL,
                usage_json JSON NULL,
                created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                UNIQUE KEY uq_api_request_logs_request_id (request_id),
                KEY idx_api_request_logs_created (created_at),
                KEY idx_api_request_logs_key_created (api_key_id, created_at),
                KEY idx_api_request_logs_status_created (status_code, created_at),
                CONSTRAINT fk_api_request_logs_key
                    FOREIGN KEY (api_key_id) REFERENCES api_keys (id) ON DELETE SET NULL,
                CONSTRAINT chk_api_request_status CHECK (status_code BETWEEN 100 AND 599)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE api_request_logs');
        $pdo->exec('DROP TABLE api_rate_limit_buckets');
        $pdo->exec('DROP TABLE api_keys');
    }
};
