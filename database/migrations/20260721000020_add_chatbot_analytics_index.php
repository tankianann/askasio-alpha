<?php

declare(strict_types=1);

use App\Database\Migration;

return new class implements Migration {
    public function up(\PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE chatbot_sessions ADD KEY idx_chatbot_sessions_activity_analytics (last_activity_at, is_test, chatbot_id, id)');
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE chatbot_sessions DROP INDEX idx_chatbot_sessions_activity_analytics');
    }
};
