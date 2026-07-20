<?php

declare(strict_types=1);

namespace App\Services\Chatbots;

use App\Domain\Chatbots\ChatbotMessage;
use App\Domain\Chatbots\ChatbotMessageRole;
use App\Domain\Chatbots\ChatbotMessageStatus;
use App\Domain\Chatbots\ChatbotSelectedHistory;
use App\Ingestion\Chunking\HeuristicTokenEstimator;

final readonly class ChatbotHistorySelector
{
    public function __construct(
        private HeuristicTokenEstimator $tokens,
        private int $maximumTokens,
    ) {
        if ($this->maximumTokens < 0 || $this->maximumTokens > 8_000) {
            throw new \InvalidArgumentException('Chatbot history tokens must be between 0 and 8000.');
        }
    }

    /** @param list<ChatbotMessage> $messages */
    public function select(array $messages, int $currentUserMessageId): ChatbotSelectedHistory
    {
        if ($this->maximumTokens === 0) {
            return new ChatbotSelectedHistory([], 0);
        }

        /** @var array<int, ChatbotMessage> $completedUsers */
        $completedUsers = [];
        /** @var array<int, ChatbotMessage> $completedReplies */
        $completedReplies = [];

        foreach ($messages as $message) {
            if ($message->status !== ChatbotMessageStatus::Completed || $message->content === null) {
                continue;
            }

            if ($message->role === ChatbotMessageRole::User && $message->id !== $currentUserMessageId) {
                $completedUsers[$message->id] = $message;
            } elseif ($message->role === ChatbotMessageRole::Assistant && $message->replyToMessageId !== null) {
                $completedReplies[$message->replyToMessageId] = $message;
            }
        }

        $turns = [];

        foreach ($completedUsers as $id => $user) {
            $assistant = $completedReplies[$id] ?? null;

            if ($assistant instanceof ChatbotMessage) {
                $turns[] = [$user, $assistant];
            }
        }

        $selected = [];
        $used = 0;

        foreach (array_reverse($turns) as [$user, $assistant]) {
            $required = $this->tokens->estimate((string) $user->content)
                + $this->tokens->estimate((string) $assistant->content)
                + 16;

            if ($used + $required > $this->maximumTokens) {
                break;
            }

            array_unshift(
                $selected,
                ['role' => 'user', 'content' => (string) $user->content],
                ['role' => 'assistant', 'content' => (string) $assistant->content],
            );
            $used += $required;
        }

        return new ChatbotSelectedHistory($selected, $used);
    }

    public function maximumTokens(): int
    {
        return $this->maximumTokens;
    }
}
