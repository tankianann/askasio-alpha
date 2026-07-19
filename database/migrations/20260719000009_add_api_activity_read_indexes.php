<?php

declare(strict_types=1);

use App\Database\Migration;

return new class implements Migration {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
            ALTER TABLE api_request_logs
                ADD INDEX idx_api_request_logs_endpoint_created (endpoint, created_at),
                ADD INDEX idx_api_request_logs_duration_created (duration_ms, created_at)
            SQL);
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
            ALTER TABLE api_request_logs
                DROP INDEX idx_api_request_logs_endpoint_created,
                DROP INDEX idx_api_request_logs_duration_created
            SQL);
    }
};
