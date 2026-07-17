<?php

declare(strict_types=1);

namespace App\Domain\Ingestion;

final class Chunk
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public readonly int $number,
        public readonly string $content,
        public readonly int $tokenCount,
        public readonly array $metadata,
    ) {
    }
}
