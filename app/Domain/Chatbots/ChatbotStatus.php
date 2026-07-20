<?php

declare(strict_types=1);

namespace App\Domain\Chatbots;

enum ChatbotStatus: string
{
    case Active = 'active';
    case Disabled = 'disabled';
    case Archived = 'archived';
}

