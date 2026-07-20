<?php

declare(strict_types=1);

namespace App\Domain\Chatbots;

enum ChatbotMessageStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
