<?php

declare(strict_types=1);

use App\Database\Migration;

return new class implements Migration {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
            CREATE TABLE chatbot_sessions (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                public_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                token_prefix VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                chatbot_id BIGINT UNSIGNED NOT NULL,
                chatbot_publication_id BIGINT UNSIGNED NOT NULL,
                channel VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                normalized_origin VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL,
                is_test TINYINT(1) NOT NULL DEFAULT 0,
                status VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active',
                message_count INT UNSIGNED NOT NULL DEFAULT 0,
                maximum_messages INT UNSIGNED NOT NULL,
                maximum_message_characters INT UNSIGNED NOT NULL,
                idle_timeout_minutes INT UNSIGNED NOT NULL,
                retention_days SMALLINT UNSIGNED NOT NULL,
                input_tokens BIGINT UNSIGNED NOT NULL DEFAULT 0,
                output_tokens BIGINT UNSIGNED NOT NULL DEFAULT 0,
                embedding_tokens BIGINT UNSIGNED NOT NULL DEFAULT 0,
                provider_tokens BIGINT UNSIGNED NOT NULL DEFAULT 0,
                started_at DATETIME(6) NOT NULL,
                last_activity_at DATETIME(6) NOT NULL,
                idle_expires_at DATETIME(6) NOT NULL,
                absolute_expires_at DATETIME(6) NOT NULL,
                completed_at DATETIME(6) NULL,
                purge_eligible_at DATETIME(6) NULL,
                created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
                UNIQUE KEY uq_chatbot_sessions_public_id (public_id),
                UNIQUE KEY uq_chatbot_sessions_token_hash (token_hash),
                KEY idx_chatbot_sessions_chatbot_activity (chatbot_id, is_test, last_activity_at, id),
                KEY idx_chatbot_sessions_publication (chatbot_publication_id, id),
                KEY idx_chatbot_sessions_status_idle (status, idle_expires_at, id),
                KEY idx_chatbot_sessions_status_absolute (status, absolute_expires_at, id),
                KEY idx_chatbot_sessions_retention (purge_eligible_at, id),
                CONSTRAINT fk_chatbot_sessions_chatbot
                    FOREIGN KEY (chatbot_id) REFERENCES chatbots (id) ON DELETE CASCADE,
                CONSTRAINT fk_chatbot_sessions_publication
                    FOREIGN KEY (chatbot_publication_id) REFERENCES chatbot_publications (id) ON DELETE CASCADE,
                CONSTRAINT chk_chatbot_sessions_channel
                    CHECK (channel IN ('browser', 'admin_preview', 'integration')),
                CONSTRAINT chk_chatbot_sessions_test CHECK (is_test IN (0, 1)),
                CONSTRAINT chk_chatbot_sessions_status
                    CHECK (status IN ('active', 'completed', 'expired', 'blocked')),
                CONSTRAINT chk_chatbot_sessions_maximum_messages CHECK (maximum_messages > 0),
                CONSTRAINT chk_chatbot_sessions_maximum_characters CHECK (maximum_message_characters > 0),
                CONSTRAINT chk_chatbot_sessions_idle_timeout CHECK (idle_timeout_minutes > 0),
                CONSTRAINT chk_chatbot_sessions_retention CHECK (retention_days IN (0, 7, 30, 90)),
                CONSTRAINT chk_chatbot_sessions_expiry CHECK (absolute_expires_at >= idle_expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        $pdo->exec(<<<'SQL'
            CREATE TABLE chatbot_messages (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                session_id BIGINT UNSIGNED NOT NULL,
                reply_to_message_id BIGINT UNSIGNED NULL,
                role VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                status VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                content LONGTEXT NULL,
                content_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
                idempotency_key_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
                request_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                provider VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NULL,
                model VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NULL,
                latency_ms INT UNSIGNED NULL,
                input_tokens INT UNSIGNED NOT NULL DEFAULT 0,
                output_tokens INT UNSIGNED NOT NULL DEFAULT 0,
                embedding_tokens INT UNSIGNED NOT NULL DEFAULT 0,
                provider_tokens INT UNSIGNED NOT NULL DEFAULT 0,
                retrieval_json JSON NULL,
                citations_json JSON NULL,
                error_code VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NULL,
                created_at DATETIME(6) NOT NULL,
                completed_at DATETIME(6) NULL,
                UNIQUE KEY uq_chatbot_messages_idempotency (session_id, idempotency_key_hash),
                UNIQUE KEY uq_chatbot_messages_request (session_id, role, request_id),
                UNIQUE KEY uq_chatbot_messages_reply (reply_to_message_id),
                KEY idx_chatbot_messages_session_created (session_id, created_at, id),
                KEY idx_chatbot_messages_pending (status, created_at, id),
                CONSTRAINT fk_chatbot_messages_session
                    FOREIGN KEY (session_id) REFERENCES chatbot_sessions (id) ON DELETE CASCADE,
                CONSTRAINT fk_chatbot_messages_reply
                    FOREIGN KEY (reply_to_message_id) REFERENCES chatbot_messages (id) ON DELETE CASCADE,
                CONSTRAINT chk_chatbot_messages_role CHECK (role IN ('user', 'assistant')),
                CONSTRAINT chk_chatbot_messages_status
                    CHECK (status IN ('pending', 'completed', 'failed', 'cancelled')),
                CONSTRAINT chk_chatbot_messages_tokens
                    CHECK (input_tokens >= 0 AND output_tokens >= 0 AND embedding_tokens >= 0 AND provider_tokens >= 0)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE chatbot_messages');
        $pdo->exec('DROP TABLE chatbot_sessions');
    }
};
