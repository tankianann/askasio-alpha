<?php

declare(strict_types=1);

use App\Services\Ingestion\IngestionQueue;
use Psr\Log\LoggerInterface;

try {
    $application = require dirname(__DIR__) . '/bootstrap/queue.php';
    /** @var IngestionQueue $queue */
    $queue = $application['queue'];
    /** @var LoggerInterface $logger */
    $logger = $application['logger'];
    $result = $queue->recoverAbandoned();
    $logger->info('Manual abandoned-job recovery completed.', $result);
    fwrite(STDOUT, sprintf(
        "Recovery complete: %d completed, %d retried, %d failed.\n",
        $result['completed'],
        $result['retried'],
        $result['failed'],
    ));
} catch (Throwable $exception) {
    fwrite(STDERR, "Job recovery failed. Review the application log and database configuration.\n");
    exit(1);
}
