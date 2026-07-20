<?php

declare(strict_types=1);

namespace App\Domain\Chatbots;

enum ChatbotSessionStatus: string
{
    case Active = 'active';
    case Completed = 'completed';
    case Expired = 'expired';
    case Blocked = 'blocked';
}
