<?php

declare(strict_types=1);

use App\Database\Migration;

return new class implements Migration {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
            ALTER TABLE api_request_logs
                ADD COLUMN access_method VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'unauthenticated' AFTER api_key_id,
                ADD COLUMN chatbot_api_key_id BIGINT UNSIGNED NULL AFTER access_method,
                ADD COLUMN chatbot_id BIGINT UNSIGNED NULL AFTER chatbot_api_key_id,
                ADD KEY idx_api_request_logs_access_created (access_method, created_at),
                ADD KEY idx_api_request_logs_chatbot_key_created (chatbot_api_key_id, created_at),
                ADD KEY idx_api_request_logs_chatbot_created (chatbot_id, created_at),
                ADD CONSTRAINT chk_api_request_access_method CHECK (
                    access_method IN ('general_api_key', 'chatbot_api_key', 'browser_chatbot', 'admin_preview', 'unauthenticated')
                )
            SQL);

        $pdo->exec(<<<'SQL'
            UPDATE api_request_logs
            SET access_method = CASE
                WHEN api_key_id IS NOT NULL THEN 'general_api_key'
                WHEN endpoint LIKE '/api/integrations/v1/%' THEN 'chatbot_api_key'
                WHEN endpoint LIKE '/api/public/v1/%' THEN 'browser_chatbot'
                ELSE 'unauthenticated'
            END
            SQL);

        $pdo->exec(<<<'SQL'
            ALTER TABLE chatbot_sessions
                ADD COLUMN chatbot_api_key_id BIGINT UNSIGNED NULL AFTER chatbot_id,
                ADD KEY idx_chatbot_sessions_chatbot_api_key (chatbot_api_key_id, started_at)
            SQL);

        $pdo->exec(<<<'SQL'
            ALTER TABLE provider_quota_reservations
                ADD COLUMN access_method VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'unauthenticated' AFTER api_key_id,
                ADD COLUMN chatbot_api_key_id BIGINT UNSIGNED NULL AFTER access_method,
                ADD COLUMN chatbot_id BIGINT UNSIGNED NULL AFTER chatbot_api_key_id,
                ADD CONSTRAINT chk_provider_quota_access_method CHECK (
                    access_method IN ('general_api_key', 'chatbot_api_key', 'browser_chatbot', 'admin_preview', 'unauthenticated')
                )
            SQL);

        $pdo->exec(<<<'SQL'
            UPDATE provider_quota_reservations
            SET access_method = CASE
                WHEN api_key_id > 0 THEN 'general_api_key'
                ELSE 'unauthenticated'
            END
            SQL);

        $pdo->exec(<<<'SQL'
            CREATE TABLE ai_usage_records (
                reservation_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
                access_method VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                api_key_id BIGINT UNSIGNED NULL,
                chatbot_api_key_id BIGINT UNSIGNED NULL,
                chatbot_id BIGINT UNSIGNED NULL,
                operation VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                usage_tokens BIGINT UNSIGNED NOT NULL,
                is_estimated TINYINT(1) NOT NULL DEFAULT 0,
                occurred_at DATETIME(6) NOT NULL,
                recorded_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                KEY idx_ai_usage_occurred (occurred_at, reservation_id),
                KEY idx_ai_usage_access_occurred (access_method, occurred_at),
                KEY idx_ai_usage_api_key_occurred (api_key_id, occurred_at),
                KEY idx_ai_usage_chatbot_key_occurred (chatbot_api_key_id, occurred_at),
                KEY idx_ai_usage_chatbot_occurred (chatbot_id, occurred_at),
                CONSTRAINT chk_ai_usage_access_method CHECK (
                    access_method IN ('general_api_key', 'chatbot_api_key', 'browser_chatbot', 'admin_preview', 'unauthenticated')
                ),
                CONSTRAINT chk_ai_usage_operation CHECK (operation IN ('retrieve', 'chat')),
                CONSTRAINT chk_ai_usage_estimated CHECK (is_estimated IN (0, 1))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE ai_usage_records');
        $pdo->exec('ALTER TABLE provider_quota_reservations DROP CONSTRAINT chk_provider_quota_access_method, DROP COLUMN chatbot_id, DROP COLUMN chatbot_api_key_id, DROP COLUMN access_method');
        $pdo->exec('ALTER TABLE chatbot_sessions DROP INDEX idx_chatbot_sessions_chatbot_api_key, DROP COLUMN chatbot_api_key_id');
        $pdo->exec('ALTER TABLE api_request_logs DROP CONSTRAINT chk_api_request_access_method, DROP INDEX idx_api_request_logs_chatbot_created, DROP INDEX idx_api_request_logs_chatbot_key_created, DROP INDEX idx_api_request_logs_access_created, DROP COLUMN chatbot_id, DROP COLUMN chatbot_api_key_id, DROP COLUMN access_method');
    }
};
