<?php

declare(strict_types=1);

use App\Ingestion\IngestionWorker;

try {
    $application = require dirname(__DIR__) . '/bootstrap/worker.php';
    /** @var IngestionWorker $worker */
    $worker = $application['worker'];

    if (!$worker->isAvailable()) {
        fwrite(STDERR, "Worker not started: the extraction processor is introduced in Milestone 5. Queued jobs were not claimed.\n");
        exit(2);
    }

    fwrite(STDOUT, "Ingestion worker started. Press Ctrl+C to stop.\n");
    $worker->run();
} catch (Throwable $exception) {
    fwrite(STDERR, "Worker failed. Review the application log and configuration.\n");
    exit(1);
}
