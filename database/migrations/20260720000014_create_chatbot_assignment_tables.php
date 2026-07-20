<?php

declare(strict_types=1);

use App\Database\Migration;

return new class implements Migration {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
            CREATE TABLE chatbot_draft_sources (
                chatbot_id BIGINT UNSIGNED NOT NULL,
                source_id BIGINT UNSIGNED NOT NULL,
                created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                PRIMARY KEY (chatbot_id, source_id),
                KEY idx_chatbot_draft_sources_source (source_id, chatbot_id),
                CONSTRAINT fk_chatbot_draft_sources_draft
                    FOREIGN KEY (chatbot_id) REFERENCES chatbot_drafts (chatbot_id) ON DELETE CASCADE,
                CONSTRAINT fk_chatbot_draft_sources_source
                    FOREIGN KEY (source_id) REFERENCES sources (id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        $pdo->exec(<<<'SQL'
            CREATE TABLE chatbot_draft_origins (
                chatbot_id BIGINT UNSIGNED NOT NULL,
                normalized_origin VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                PRIMARY KEY (chatbot_id, normalized_origin),
                CONSTRAINT fk_chatbot_draft_origins_draft
                    FOREIGN KEY (chatbot_id) REFERENCES chatbot_drafts (chatbot_id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        $pdo->exec(<<<'SQL'
            CREATE TABLE chatbot_publication_sources (
                publication_id BIGINT UNSIGNED NOT NULL,
                source_id BIGINT UNSIGNED NOT NULL,
                created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                PRIMARY KEY (publication_id, source_id),
                KEY idx_chatbot_publication_sources_source (source_id, publication_id),
                CONSTRAINT fk_chatbot_publication_sources_publication
                    FOREIGN KEY (publication_id) REFERENCES chatbot_publications (id) ON DELETE CASCADE,
                CONSTRAINT fk_chatbot_publication_sources_source
                    FOREIGN KEY (source_id) REFERENCES sources (id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        $pdo->exec(<<<'SQL'
            CREATE TABLE chatbot_publication_origins (
                publication_id BIGINT UNSIGNED NOT NULL,
                normalized_origin VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                PRIMARY KEY (publication_id, normalized_origin),
                CONSTRAINT fk_chatbot_publication_origins_publication
                    FOREIGN KEY (publication_id) REFERENCES chatbot_publications (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE chatbot_publication_origins');
        $pdo->exec('DROP TABLE chatbot_publication_sources');
        $pdo->exec('DROP TABLE chatbot_draft_origins');
        $pdo->exec('DROP TABLE chatbot_draft_sources');
    }
};

