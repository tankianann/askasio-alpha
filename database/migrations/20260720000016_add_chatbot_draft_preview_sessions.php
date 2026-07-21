<?php

declare(strict_types=1);

use App\Database\Migration;

return new class implements Migration {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
            ALTER TABLE chatbot_sessions
                MODIFY chatbot_publication_id BIGINT UNSIGNED NULL,
                ADD preview_draft_revision INT UNSIGNED NULL AFTER chatbot_publication_id,
                ADD preview_configuration_json JSON NULL AFTER preview_draft_revision,
                ADD CONSTRAINT chk_chatbot_sessions_execution_binding CHECK (
                    (chatbot_publication_id IS NOT NULL
                        AND preview_draft_revision IS NULL
                        AND preview_configuration_json IS NULL)
                    OR
                    (chatbot_publication_id IS NULL
                        AND preview_draft_revision IS NOT NULL
                        AND preview_draft_revision > 0
                        AND preview_configuration_json IS NOT NULL
                        AND channel = 'admin_preview'
                        AND is_test = 1)
                )
            SQL);
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DELETE FROM chatbot_sessions WHERE chatbot_publication_id IS NULL');
        $pdo->exec(<<<'SQL'
            ALTER TABLE chatbot_sessions
                DROP CONSTRAINT chk_chatbot_sessions_execution_binding,
                DROP COLUMN preview_configuration_json,
                DROP COLUMN preview_draft_revision,
                MODIFY chatbot_publication_id BIGINT UNSIGNED NOT NULL
            SQL);
    }
};
