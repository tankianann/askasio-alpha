<?php

declare(strict_types=1);

namespace App\Domain\Chatbots;

enum ChatbotListSort: string
{
    case Updated = 'updated';
    case Name = 'name';
    case Status = 'status';
    case Publication = 'publication';
}

