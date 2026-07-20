<?php

declare(strict_types=1);

namespace App\Domain\Chatbots;

enum ChatbotPublicationFilter: string
{
    case All = 'all';
    case Draft = 'draft';
    case Published = 'published';
}

