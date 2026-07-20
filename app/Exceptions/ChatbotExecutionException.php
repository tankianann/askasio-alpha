<?php

declare(strict_types=1);

namespace App\Exceptions;

use Throwable;

final class ChatbotExecutionException extends \RuntimeException
{
    public function __construct(
        public readonly int $statusCode,
        public readonly string $errorCode,
        string $safeMessage,
        ?Throwable $previous = null,
    ) {
        parent::__construct($safeMessage, previous: $previous);
    }
}
