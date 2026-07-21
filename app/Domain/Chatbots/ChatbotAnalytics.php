<?php

declare(strict_types=1);

namespace App\Domain\Chatbots;

final readonly class ChatbotAnalytics
{
    /**
     * @param list<array{chatbot_id: int, chatbot_name: string, sessions: int, messages: int, provider_tokens: int}> $byChatbot
     * @param list<array{day: string, sessions: int, messages: int, provider_tokens: int}> $daily
     */
    public function __construct(
        public int $sessions,
        public int $productionSessions,
        public int $testSessions,
        public int $activeSessions,
        public int $messages,
        public int $providerTokens,
        public int $failedAssistantMessages,
        public int $distinctChatbots,
        public array $byChatbot,
        public array $daily,
    ) {
    }
}
