<?php

declare(strict_types=1);

use App\Ingestion\IngestionWorker;

try {
    $application = require dirname(__DIR__) . '/bootstrap/worker.php';
    /** @var IngestionWorker $worker */
    $worker = $application['worker'];

    if (!$worker->isAvailable()) {
        fwrite(STDERR, "Ingestion jobs remain pending because the extraction pipeline is unavailable.\n");
        exit(2);
    }

    $processed = $worker->runOnce();
    fwrite(STDOUT, $processed ? "Processed one ingestion job.\n" : "No available ingestion jobs.\n");
} catch (Throwable $exception) {
    fwrite(STDERR, "Job processing failed. Review the application log and configuration.\n");
    exit(1);
}
