<?php

declare(strict_types=1);

use App\Database\Migration;

return new class implements Migration {
    public function up(\PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE api_request_logs DROP FOREIGN KEY fk_api_request_logs_key');
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
            ALTER TABLE api_request_logs
                ADD CONSTRAINT fk_api_request_logs_key
                FOREIGN KEY (api_key_id) REFERENCES api_keys (id) ON DELETE SET NULL
            SQL);
    }
};
