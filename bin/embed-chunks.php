<?php

declare(strict_types=1);

use App\RAG\EmbeddingBackfillService;
use App\RAG\VectorStoreInterface;

try {
    $application = require dirname(__DIR__) . '/bootstrap/rag.php';
    /** @var EmbeddingBackfillService $backfill */
    $backfill = $application['backfill'];
    /** @var VectorStoreInterface $vectors */
    $vectors = $application['vectors'];
    $once = in_array('--once', $argv, true);
    $total = 0;

    if (in_array('--rebuild', $argv, true)) {
        $cleared = $vectors->clearActiveEmbeddings();
        fwrite(STDOUT, sprintf("Cleared %d active chunk embedding(s) for rebuilding.\n", $cleared));
    }

    do {
        $processed = $backfill->runBatch();
        $total += $processed;

        if ($processed > 0) {
            fwrite(STDOUT, sprintf("Embedded %d chunk(s).\n", $processed));
        }
    } while (!$once && $processed > 0);

    fwrite(STDOUT, sprintf("Embedding backfill complete. %d chunk(s) updated.\n", $total));
} catch (Throwable $exception) {
    fwrite(STDERR, "Embedding backfill failed. Review the provider configuration and application log.\n");
    exit(1);
}
