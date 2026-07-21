<?php

declare(strict_types=1);

namespace App\Maintenance;

final class ChatbotConversationMaintenanceLock
{
    public static function name(string $database): string
    {
        return substr('askasio:chatbot-conversation-retention:' . hash('sha256', $database), 0, 64);
    }
}
