<?php

declare(strict_types=1);

namespace App\Domain\Chatbots;

final readonly class PublicChatbotConfiguration
{
    /**
     * @param list<string> $suggestedQuestions
     * @param array{layout: string, accent: string, theme: string, position: string, launcher_label: string, launcher_icon: string, panel_title: string, size: string} $appearance
     */
    public function __construct(
        public string $id,
        public int $configurationVersion,
        public string $displayName,
        public string $welcomeMessage,
        public array $suggestedQuestions,
        public string $inputPlaceholder,
        public array $appearance,
        public bool $citations,
        public int $maximumMessageCharacters,
        public ?string $privacyNoticeUrl,
        public string $disclosureText,
    ) {
    }

    public static function fromContext(PublicChatbotContext $context): self
    {
        $configuration = $context->publication->configuration;
        $presentation = $configuration->presentation;
        $appearance = $configuration->appearance;
        $suggested = $presentation['suggested_questions'] ?? [];

        return new self(
            $context->chatbot->publicId,
            $context->publication->publicationNumber,
            is_string($presentation['display_name'] ?? null) ? $presentation['display_name'] : '',
            is_string($presentation['welcome_message'] ?? null) ? $presentation['welcome_message'] : '',
            is_array($suggested)
                ? array_values(array_filter($suggested, static fn (mixed $value): bool => is_string($value)))
                : [],
            is_string($presentation['input_placeholder'] ?? null) ? $presentation['input_placeholder'] : '',
            [
                'layout' => ($appearance['layout'] ?? null) === 'inline_fullscreen' ? 'inline_fullscreen' : 'floating',
                'accent' => is_string($appearance['accent'] ?? null) ? $appearance['accent'] : '#2457d6',
                'theme' => is_string($appearance['theme'] ?? null) ? $appearance['theme'] : 'light',
                'position' => is_string($appearance['position'] ?? null) ? $appearance['position'] : 'right',
                'launcher_label' => is_string($appearance['launcher_label'] ?? null) ? $appearance['launcher_label'] : 'Chat',
                'launcher_icon' => is_string($appearance['launcher_icon'] ?? null) ? $appearance['launcher_icon'] : 'chat',
                'panel_title' => is_string($appearance['panel_title'] ?? null) ? $appearance['panel_title'] : 'Chat',
                'size' => is_string($appearance['size'] ?? null) ? $appearance['size'] : 'standard',
            ],
            $configuration->citationsEnabled,
            $configuration->maximumMessageCharacters,
            $configuration->privacyNoticeUrl,
            $configuration->disclosureText,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'configuration_version' => $this->configurationVersion,
            'display_name' => $this->displayName,
            'welcome_message' => $this->welcomeMessage,
            'suggested_questions' => $this->suggestedQuestions,
            'input_placeholder' => $this->inputPlaceholder,
            'appearance' => $this->appearance,
            'capabilities' => [
                'citations' => $this->citations,
                'restart' => true,
                'streaming' => false,
            ],
            'maximum_message_characters' => $this->maximumMessageCharacters,
            'privacy_notice_url' => $this->privacyNoticeUrl,
            'disclosure_text' => $this->disclosureText,
        ];
    }
}
