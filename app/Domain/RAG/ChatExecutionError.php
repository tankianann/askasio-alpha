<?php

declare(strict_types=1);

namespace App\Domain\RAG;

final readonly class ChatExecutionError
{
    public function __construct(
        public int $statusCode,
        public string $errorCode,
        public string $safeMessage,
    ) {
    }
}
