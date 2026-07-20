<?php

declare(strict_types=1);

namespace App\Ingestion;

use InvalidArgumentException;

final class WorkerRuntimePolicy
{
    private const SAFETY_MARGIN_SECONDS = 300;

    public readonly int $minimumAbandonedTimeoutSeconds;

    public function __construct(
        int $abandonedTimeoutSeconds,
        int $urlConnectTimeoutSeconds,
        int $urlRequestTimeoutSeconds,
        int $urlMaximumRedirects,
        bool $pdfOcrEnabled,
        int $pdfOcrProcessTimeoutSeconds,
        int $pdfOcrPageTimeoutSeconds,
        int $maximumChunks,
        int $embeddingBatchSize,
        int $providerRequestTimeoutSeconds,
        int $providerMaximumRetries,
    ) {
        if ($urlConnectTimeoutSeconds < 1
            || $urlRequestTimeoutSeconds < 1
            || $urlConnectTimeoutSeconds > $urlRequestTimeoutSeconds) {
            throw new InvalidArgumentException(
                'URL_CONNECT_TIMEOUT_SECONDS must be positive and no greater than URL_REQUEST_TIMEOUT_SECONDS.',
            );
        }

        if ($urlMaximumRedirects < 0 || $urlMaximumRedirects > 20) {
            throw new InvalidArgumentException('URL_MAXIMUM_REDIRECTS must be between 0 and 20.');
        }

        if ($pdfOcrEnabled
            && ($pdfOcrProcessTimeoutSeconds < 1
                || $pdfOcrPageTimeoutSeconds < 1
                || $pdfOcrPageTimeoutSeconds > $pdfOcrProcessTimeoutSeconds)) {
            throw new InvalidArgumentException(
                'PDF_OCR_PAGE_TIMEOUT_SECONDS must be positive and no greater than PDF_OCR_PROCESS_TIMEOUT_SECONDS.',
            );
        }

        if ($maximumChunks < 1 || $embeddingBatchSize < 1) {
            throw new InvalidArgumentException('Chunk and embedding batch limits must be positive.');
        }

        if ($providerRequestTimeoutSeconds < 1 || $providerMaximumRetries < 0 || $providerMaximumRetries > 10) {
            throw new InvalidArgumentException('Embedding provider timeout or retry configuration is invalid.');
        }

        $maximumEmbeddingBatches = (int) ceil($maximumChunks / $embeddingBatchSize);
        $maximumBatchSeconds = ($providerMaximumRetries + 1) * $providerRequestTimeoutSeconds
            + $this->maximumBackoffSeconds($providerMaximumRetries);
        $maximumExtractionSeconds = $urlRequestTimeoutSeconds * ($urlMaximumRedirects + 1);

        if ($pdfOcrEnabled) {
            $maximumExtractionSeconds = max($maximumExtractionSeconds, $pdfOcrProcessTimeoutSeconds);
        }

        $this->minimumAbandonedTimeoutSeconds = $maximumExtractionSeconds
            + ($maximumEmbeddingBatches * $maximumBatchSeconds)
            + self::SAFETY_MARGIN_SECONDS;

        if ($abandonedTimeoutSeconds < $this->minimumAbandonedTimeoutSeconds) {
            throw new InvalidArgumentException(sprintf(
                'JOB_ABANDONED_TIMEOUT_MINUTES is too short for the configured ingestion limits; use at least %d minutes.',
                (int) ceil($this->minimumAbandonedTimeoutSeconds / 60),
            ));
        }
    }

    private function maximumBackoffSeconds(int $retries): int
    {
        $milliseconds = 0;

        for ($attempt = 1; $attempt <= $retries; $attempt++) {
            $milliseconds += min(4_000, 250 * (2 ** max(0, $attempt - 1))) + 250;
        }

        return (int) ceil($milliseconds / 1000);
    }
}
