<?php

declare(strict_types=1);

namespace App\Domain\Ingestion;

final class FetchedPage
{
    /** @param array<string, string> $headers */
    public function __construct(
        public readonly string $url,
        public readonly string $body,
        public readonly string $contentType,
        public readonly int $statusCode,
        public readonly array $headers = [],
    ) {
    }
}
