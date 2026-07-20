<?php

declare(strict_types=1);

namespace App\Domain\Chatbots;

enum ChatbotMessageRole: string
{
    case User = 'user';
    case Assistant = 'assistant';
}
