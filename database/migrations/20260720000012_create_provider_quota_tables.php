<?php

declare(strict_types=1);

use App\Database\Migration;

return new class implements Migration {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
            CREATE TABLE provider_quota_buckets (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                scope VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                identifier_id BIGINT UNSIGNED NOT NULL,
                period_type VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                period_start DATE NOT NULL,
                consumed_tokens BIGINT UNSIGNED NOT NULL DEFAULT 0,
                reserved_tokens BIGINT UNSIGNED NOT NULL DEFAULT 0,
                created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
                UNIQUE KEY uq_provider_quota_bucket (scope, identifier_id, period_type, period_start),
                KEY idx_provider_quota_period (period_type, period_start),
                CONSTRAINT chk_provider_quota_scope CHECK (scope IN ('global', 'api_key')),
                CONSTRAINT chk_provider_quota_period CHECK (period_type IN ('daily', 'monthly'))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        $pdo->exec(<<<'SQL'
            CREATE TABLE provider_quota_reservations (
                id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
                api_key_id BIGINT UNSIGNED NOT NULL,
                operation VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                reserved_tokens BIGINT UNSIGNED NOT NULL,
                actual_tokens BIGINT UNSIGNED NULL,
                daily_period_start DATE NOT NULL,
                monthly_period_start DATE NOT NULL,
                status VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active',
                expires_at DATETIME(6) NOT NULL,
                created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                reconciled_at DATETIME(6) NULL,
                KEY idx_provider_quota_reservation_expiry (status, expires_at),
                KEY idx_provider_quota_reservation_key_created (api_key_id, created_at),
                CONSTRAINT chk_provider_quota_operation CHECK (operation IN ('retrieve', 'chat')),
                CONSTRAINT chk_provider_quota_reservation_status CHECK (status IN ('active', 'reconciled', 'estimated')),
                CONSTRAINT chk_provider_quota_reserved_positive CHECK (reserved_tokens > 0)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE provider_quota_reservations');
        $pdo->exec('DROP TABLE provider_quota_buckets');
    }
};
