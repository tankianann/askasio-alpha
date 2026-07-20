<?php

declare(strict_types=1);

namespace App\Domain\Chatbots;

enum ChatbotExecutionAudience: string
{
    case Public = 'public';
    case AdminPreview = 'admin_preview';
}
