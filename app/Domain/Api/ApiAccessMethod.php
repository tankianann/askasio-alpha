<?php

declare(strict_types=1);

namespace App\Domain\Api;

enum ApiAccessMethod: string
{
    case All = 'all';
    case GeneralApiKey = 'general_api_key';
    case ChatbotApiKey = 'chatbot_api_key';
    case BrowserChatbot = 'browser_chatbot';
    case AdminPreview = 'admin_preview';
    case Unauthenticated = 'unauthenticated';

    public function label(): string
    {
        return match ($this) {
            self::All => 'All access methods',
            self::GeneralApiKey => 'General API Key',
            self::ChatbotApiKey => 'Chatbot API Key',
            self::BrowserChatbot => 'Browser chatbot',
            self::AdminPreview => 'Administrator preview',
            self::Unauthenticated => 'Authentication failed',
        };
    }
}
