<?php

declare(strict_types=1);

namespace App\Domain\Chatbots;

final readonly class PublicChatbotMessage
{
    /** @param list<array<string, mixed>> $citations */
    public function __construct(
        public string $sessionId,
        public string $messageId,
        public string $answer,
        public array $citations,
        public int $retrievedChunks,
        public bool $fallback,
        public bool $replayed,
    ) {
    }

    public static function fromResult(ChatbotSession $session, ChatbotExecutionResult $result): self
    {
        $retrieval = $result->message->retrieval ?? [];

        return new self(
            $session->publicId,
            $result->message->requestId,
            $result->answer,
            $result->citations,
            is_int($retrieval['retrieved_chunks'] ?? null) ? $retrieval['retrieved_chunks'] : 0,
            $result->fallback,
            $result->replayed,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'session_id' => $this->sessionId,
            'message_id' => $this->messageId,
            'answer' => $this->answer,
            'citations' => $this->citations,
            'usage' => ['retrieved_chunks' => $this->retrievedChunks],
            'fallback' => $this->fallback,
            'replayed' => $this->replayed,
        ];
    }
}
