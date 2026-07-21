<?php

declare(strict_types=1);

use App\Database\Migration;

return new class implements Migration {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
            CREATE TABLE chatbot_integration_credentials (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                created_by_admin_id BIGINT UNSIGNED NOT NULL,
                name VARCHAR(190) NOT NULL,
                visible_prefix VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                secret_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                status VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active',
                request_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
                provider_tokens BIGINT UNSIGNED NOT NULL DEFAULT 0,
                created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
                last_used_at DATETIME(6) NULL,
                expires_at DATETIME(6) NULL,
                revoked_at DATETIME(6) NULL,
                UNIQUE KEY uq_chatbot_integration_credentials_hash (secret_hash),
                KEY idx_chatbot_integration_credentials_status_created (status, created_at, id),
                CONSTRAINT fk_chatbot_integration_credentials_admin FOREIGN KEY (created_by_admin_id) REFERENCES admin_users (id) ON DELETE RESTRICT,
                CONSTRAINT chk_chatbot_integration_credentials_status CHECK (status IN ('active', 'revoked'))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
        $pdo->exec(<<<'SQL'
            CREATE TABLE chatbot_integration_credential_scopes (
                credential_id BIGINT UNSIGNED NOT NULL,
                chatbot_id BIGINT UNSIGNED NOT NULL,
                created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                PRIMARY KEY (credential_id, chatbot_id),
                KEY idx_chatbot_integration_scopes_chatbot (chatbot_id, credential_id),
                CONSTRAINT fk_chatbot_integration_scopes_credential FOREIGN KEY (credential_id) REFERENCES chatbot_integration_credentials (id) ON DELETE CASCADE,
                CONSTRAINT fk_chatbot_integration_scopes_chatbot FOREIGN KEY (chatbot_id) REFERENCES chatbots (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
        $pdo->exec(<<<'SQL'
            ALTER TABLE api_rate_limit_buckets
                DROP CONSTRAINT chk_api_rate_scope_v3,
                ADD CONSTRAINT chk_api_rate_scope_v4 CHECK (scope IN ('api_key', 'ip', 'chatbot', 'session', 'integration'))
            SQL);
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec("DELETE FROM api_rate_limit_buckets WHERE scope = 'integration'");
        $pdo->exec('ALTER TABLE api_rate_limit_buckets DROP CONSTRAINT chk_api_rate_scope_v4, ADD CONSTRAINT chk_api_rate_scope_v3 CHECK (scope IN (\'api_key\', \'ip\', \'chatbot\', \'session\'))');
        $pdo->exec('DROP TABLE chatbot_integration_credential_scopes');
        $pdo->exec('DROP TABLE chatbot_integration_credentials');
    }
};
