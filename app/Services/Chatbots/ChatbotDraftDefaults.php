<?php

declare(strict_types=1);

namespace App\Services\Chatbots;

use App\Domain\Chatbots\ChatbotDraft;

final readonly class ChatbotDraftDefaults
{
    public function __construct(
        private int $topK,
        private float $minimumSimilarity,
        private int $maximumMessageCharacters,
    ) {
        if ($topK < 1 || !is_finite($minimumSimilarity) || $minimumSimilarity < 0 || $minimumSimilarity > 1
            || $maximumMessageCharacters < 1) {
            throw new \InvalidArgumentException('Chatbot draft defaults are invalid.');
        }
    }

    public function create(string $displayName): ChatbotDraft
    {
        $displayName = mb_substr(trim($displayName), 0, 100);

        return new ChatbotDraft(
            1,
            1,
            'Answer only from the assigned Ask Asio knowledge sources. If the sources do not support an answer, use the fallback message.',
            'I could not find enough information in the available sources to answer that question.',
            $this->topK,
            $this->minimumSimilarity,
            true,
            $this->maximumMessageCharacters,
            40,
            30,
            480,
            30,
            null,
            'You are chatting with an automated assistant.',
            [
                'display_name' => $displayName,
                'welcome_message' => 'How can I help?',
                'input_placeholder' => 'Ask a question',
                'suggested_questions' => [],
            ],
            [
                'layout' => 'floating',
                'accent' => '#0B7BDD',
                'theme' => 'light',
                'position' => 'right',
                'launcher_label' => 'Chat',
                'launcher_icon' => 'chat',
                'panel_title' => $displayName,
                'size' => 'standard',
            ],
        );
    }
}
