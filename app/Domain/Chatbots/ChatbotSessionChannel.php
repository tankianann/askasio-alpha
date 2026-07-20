<?php

declare(strict_types=1);

namespace App\Domain\Chatbots;

enum ChatbotSessionChannel: string
{
    case Browser = 'browser';
    case AdminPreview = 'admin_preview';
    case Integration = 'integration';
}
