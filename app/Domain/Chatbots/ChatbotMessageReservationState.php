<?php

declare(strict_types=1);

namespace App\Domain\Chatbots;

enum ChatbotMessageReservationState: string
{
    case Reserved = 'reserved';
    case InProgress = 'in_progress';
    case Replay = 'replay';
}
