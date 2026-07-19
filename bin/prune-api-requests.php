<?php

declare(strict_types=1);

use App\Services\Api\ApiRequestLogRetentionService;
use Psr\Log\LoggerInterface;

try {
    $application = require dirname(__DIR__) . '/bootstrap/maintenance.php';
    /** @var ApiRequestLogRetentionService $retention */
    $retention = $application['api_request_log_retention'];
    /** @var LoggerInterface $logger */
    $logger = $application['logger'];
    $result = $retention->run();

    if (!$result->enabled) {
        fwrite(STDOUT, "API Activity retention is disabled; records are kept forever.\n");
        exit(0);
    }

    if (!$result->lockAcquired) {
        fwrite(STDOUT, "Another API Activity retention run is already in progress.\n");
        exit(0);
    }

    fwrite(STDOUT, sprintf(
        "API Activity retention complete: %d records deleted in %d batches (cutoff %s UTC).\n",
        $result->deletedRecords,
        $result->batches,
        $result->cutoffUtc,
    ));
} catch (Throwable $exception) {
    if (isset($logger) && $logger instanceof LoggerInterface) {
        $logger->error('Scheduled API request log retention failed.', ['exception' => $exception]);
    }

    fwrite(STDERR, "API Activity retention failed. Review the application log and configuration.\n");
    exit(1);
}
