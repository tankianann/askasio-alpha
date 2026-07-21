<?php

declare(strict_types=1);

use App\Database\Migration;

return new class implements Migration {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
            ALTER TABLE api_rate_limit_buckets
                DROP CHECK chk_api_rate_scope,
                ADD CONSTRAINT chk_api_rate_scope_v2 CHECK (scope IN ('api_key', 'ip', 'chatbot'))
            SQL);
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec("DELETE FROM api_rate_limit_buckets WHERE scope = 'chatbot'");
        $pdo->exec(<<<'SQL'
            ALTER TABLE api_rate_limit_buckets
                DROP CHECK chk_api_rate_scope_v2,
                ADD CONSTRAINT chk_api_rate_scope CHECK (scope IN ('api_key', 'ip'))
            SQL);
    }
};
