<?php

declare(strict_types=1);

use App\Database\Migration;

return new class implements Migration {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
            CREATE TABLE chatbots (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                public_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                name VARCHAR(190) NOT NULL,
                description TEXT NULL,
                status VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active',
                active_publication_id BIGINT UNSIGNED NULL,
                created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
                archived_at DATETIME(6) NULL,
                UNIQUE KEY uq_chatbots_public_id (public_id),
                KEY idx_chatbots_status_updated (status, updated_at, id),
                KEY idx_chatbots_name (name, id),
                KEY idx_chatbots_updated (updated_at, id),
                CONSTRAINT chk_chatbots_status CHECK (status IN ('active', 'disabled', 'archived'))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        $pdo->exec(<<<'SQL'
            CREATE TABLE chatbot_drafts (
                chatbot_id BIGINT UNSIGNED PRIMARY KEY,
                schema_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
                revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
                system_instructions TEXT NOT NULL,
                fallback_message VARCHAR(1000) NOT NULL,
                retrieval_top_k SMALLINT UNSIGNED NOT NULL,
                minimum_similarity DECIMAL(6,5) NOT NULL,
                citations_enabled TINYINT(1) NOT NULL DEFAULT 1,
                maximum_message_characters INT UNSIGNED NOT NULL,
                maximum_messages_per_session SMALLINT UNSIGNED NOT NULL,
                idle_expiry_minutes INT UNSIGNED NOT NULL,
                absolute_expiry_minutes INT UNSIGNED NOT NULL,
                retention_days SMALLINT UNSIGNED NOT NULL DEFAULT 30,
                privacy_notice_url VARCHAR(2048) NULL,
                disclosure_text VARCHAR(500) NOT NULL,
                presentation_json JSON NOT NULL,
                appearance_json JSON NOT NULL,
                updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
                CONSTRAINT fk_chatbot_drafts_chatbot
                    FOREIGN KEY (chatbot_id) REFERENCES chatbots (id) ON DELETE CASCADE,
                CONSTRAINT chk_chatbot_drafts_schema CHECK (schema_version > 0),
                CONSTRAINT chk_chatbot_drafts_revision CHECK (revision > 0),
                CONSTRAINT chk_chatbot_drafts_top_k CHECK (retrieval_top_k > 0),
                CONSTRAINT chk_chatbot_drafts_similarity CHECK (minimum_similarity BETWEEN 0 AND 1),
                CONSTRAINT chk_chatbot_drafts_citations CHECK (citations_enabled IN (0, 1)),
                CONSTRAINT chk_chatbot_drafts_message_chars CHECK (maximum_message_characters > 0),
                CONSTRAINT chk_chatbot_drafts_message_count CHECK (maximum_messages_per_session > 0),
                CONSTRAINT chk_chatbot_drafts_idle_expiry CHECK (idle_expiry_minutes > 0),
                CONSTRAINT chk_chatbot_drafts_absolute_expiry CHECK (absolute_expiry_minutes >= idle_expiry_minutes),
                CONSTRAINT chk_chatbot_drafts_retention CHECK (retention_days IN (0, 7, 30, 90))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        $pdo->exec(<<<'SQL'
            CREATE TABLE chatbot_publications (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                chatbot_id BIGINT UNSIGNED NOT NULL,
                publication_number INT UNSIGNED NOT NULL,
                source_draft_revision BIGINT UNSIGNED NOT NULL,
                schema_version SMALLINT UNSIGNED NOT NULL,
                configuration_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                system_instructions TEXT NOT NULL,
                fallback_message VARCHAR(1000) NOT NULL,
                retrieval_top_k SMALLINT UNSIGNED NOT NULL,
                minimum_similarity DECIMAL(6,5) NOT NULL,
                citations_enabled TINYINT(1) NOT NULL,
                maximum_message_characters INT UNSIGNED NOT NULL,
                maximum_messages_per_session SMALLINT UNSIGNED NOT NULL,
                idle_expiry_minutes INT UNSIGNED NOT NULL,
                absolute_expiry_minutes INT UNSIGNED NOT NULL,
                retention_days SMALLINT UNSIGNED NOT NULL,
                privacy_notice_url VARCHAR(2048) NULL,
                disclosure_text VARCHAR(500) NOT NULL,
                presentation_json JSON NOT NULL,
                appearance_json JSON NOT NULL,
                chat_provider VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                chat_model VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                embedding_provider VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                embedding_model VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                embedding_dimensions INT UNSIGNED NULL,
                published_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                UNIQUE KEY uq_chatbot_publication_number (chatbot_id, publication_number),
                KEY idx_chatbot_publications_published (chatbot_id, published_at, id),
                CONSTRAINT fk_chatbot_publications_chatbot
                    FOREIGN KEY (chatbot_id) REFERENCES chatbots (id) ON DELETE CASCADE,
                CONSTRAINT chk_chatbot_publications_number CHECK (publication_number > 0),
                CONSTRAINT chk_chatbot_publications_draft_revision CHECK (source_draft_revision > 0),
                CONSTRAINT chk_chatbot_publications_schema CHECK (schema_version > 0),
                CONSTRAINT chk_chatbot_publications_top_k CHECK (retrieval_top_k > 0),
                CONSTRAINT chk_chatbot_publications_similarity CHECK (minimum_similarity BETWEEN 0 AND 1),
                CONSTRAINT chk_chatbot_publications_citations CHECK (citations_enabled IN (0, 1)),
                CONSTRAINT chk_chatbot_publications_message_chars CHECK (maximum_message_characters > 0),
                CONSTRAINT chk_chatbot_publications_message_count CHECK (maximum_messages_per_session > 0),
                CONSTRAINT chk_chatbot_publications_idle_expiry CHECK (idle_expiry_minutes > 0),
                CONSTRAINT chk_chatbot_publications_absolute_expiry CHECK (absolute_expiry_minutes >= idle_expiry_minutes),
                CONSTRAINT chk_chatbot_publications_retention CHECK (retention_days IN (0, 7, 30, 90))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        $pdo->exec(<<<'SQL'
            ALTER TABLE chatbots
                ADD CONSTRAINT fk_chatbots_active_publication
                FOREIGN KEY (active_publication_id) REFERENCES chatbot_publications (id) ON DELETE SET NULL
            SQL);
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE chatbots DROP FOREIGN KEY fk_chatbots_active_publication');
        $pdo->exec('DROP TABLE chatbot_publications');
        $pdo->exec('DROP TABLE chatbot_drafts');
        $pdo->exec('DROP TABLE chatbots');
    }
};
