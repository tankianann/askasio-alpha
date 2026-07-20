<?php

declare(strict_types=1);

namespace App\Domain\Chatbots;

enum ChatbotListStatus: string
{
    case All = 'all';
    case Active = 'active';
    case Disabled = 'disabled';
    case Archived = 'archived';
}

