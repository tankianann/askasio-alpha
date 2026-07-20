<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Chatbots\ChatbotDraft;
use App\Domain\Chatbots\ChatbotProviderConfiguration;

final class ChatbotFixtures
{
    public static function draft(
        int $revision = 1,
        string $instructions = 'Answer only from the assigned Ask Asio knowledge sources.',
        string $displayName = 'Support assistant',
    ): ChatbotDraft {
        return new ChatbotDraft(
            1,
            $revision,
            $instructions,
            'I could not find enough information to answer that question.',
            5,
            0.2,
            true,
            4_000,
            40,
            30,
            480,
            30,
            'https://example.com/privacy',
            'You are chatting with an automated assistant.',
            [
                'display_name' => $displayName,
                'welcome_message' => 'How can I help?',
                'input_placeholder' => 'Ask a question',
                'suggested_questions' => ['What is the refund policy?'],
            ],
            [
                'accent' => '#2457d6',
                'theme' => 'light',
                'position' => 'right',
                'launcher_label' => 'Chat',
                'launcher_icon' => 'chat',
                'panel_title' => 'Support',
                'size' => 'standard',
            ],
        );
    }

    public static function provider(): ChatbotProviderConfiguration
    {
        return new ChatbotProviderConfiguration(
            'openai',
            'gpt-test-chat',
            'openai',
            'text-embedding-test',
            1536,
        );
    }
}
