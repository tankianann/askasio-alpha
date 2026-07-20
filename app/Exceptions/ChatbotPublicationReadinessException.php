<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Domain\Chatbots\ChatbotSourceReadiness;
use RuntimeException;

final class ChatbotPublicationReadinessException extends RuntimeException
{
    /** @param list<ChatbotSourceReadiness> $sources */
    public function __construct(public readonly array $sources)
    {
        parent::__construct('One or more assigned chatbot sources are not ready and embedding-compatible.');
    }
}

