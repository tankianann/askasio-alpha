<?php

declare(strict_types=1);

namespace App\Domain\Chatbots;

final readonly class ChatbotDraft
{
    /**
     * @param array<string, mixed> $presentation
     * @param array<string, mixed> $appearance
     */
    public function __construct(
        public int $schemaVersion,
        public int $revision,
        public string $systemInstructions,
        public string $fallbackMessage,
        public int $retrievalTopK,
        public float $minimumSimilarity,
        public bool $citationsEnabled,
        public int $maximumMessageCharacters,
        public int $maximumMessagesPerSession,
        public int $idleExpiryMinutes,
        public int $absoluteExpiryMinutes,
        public int $retentionDays,
        public ?string $privacyNoticeUrl,
        public string $disclosureText,
        public array $presentation,
        public array $appearance,
        public ?string $updatedAt = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function configuration(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'system_instructions' => $this->systemInstructions,
            'fallback_message' => $this->fallbackMessage,
            'retrieval_top_k' => $this->retrievalTopK,
            'minimum_similarity' => $this->minimumSimilarity,
            'citations_enabled' => $this->citationsEnabled,
            'maximum_message_characters' => $this->maximumMessageCharacters,
            'maximum_messages_per_session' => $this->maximumMessagesPerSession,
            'idle_expiry_minutes' => $this->idleExpiryMinutes,
            'absolute_expiry_minutes' => $this->absoluteExpiryMinutes,
            'retention_days' => $this->retentionDays,
            'privacy_notice_url' => $this->privacyNoticeUrl,
            'disclosure_text' => $this->disclosureText,
            'presentation' => $this->presentation,
            'appearance' => $this->appearance,
        ];
    }
}

