<?php

declare(strict_types=1);

use App\Database\Migration;

return new class implements Migration {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
            ALTER TABLE source_chunks
                ADD COLUMN embedding_model VARCHAR(190) NULL AFTER embedding,
                ADD COLUMN embedding_dimensions INT UNSIGNED NULL AFTER embedding_model,
                ADD COLUMN embedded_at DATETIME(6) NULL AFTER embedding_dimensions,
                ADD KEY idx_source_chunks_embedding_model (embedding_model)
            SQL);
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
            ALTER TABLE source_chunks
                DROP INDEX idx_source_chunks_embedding_model,
                DROP COLUMN embedded_at,
                DROP COLUMN embedding_dimensions,
                DROP COLUMN embedding_model
            SQL);
    }
};
