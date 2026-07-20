<?php

declare(strict_types=1);

namespace App\Services\Chatbots;

use App\Domain\Chatbots\ChatbotDraft;
use App\Exceptions\ValidationException;

final readonly class ChatbotDraftValidator
{
    private const RETENTION_DAYS = [0, 7, 30, 90];
    private const PRESENTATION_KEYS = ['display_name', 'welcome_message', 'input_placeholder', 'suggested_questions'];
    private const APPEARANCE_KEYS = ['accent', 'theme', 'position', 'launcher_label', 'launcher_icon', 'panel_title', 'size'];

    public function __construct(
        private int $maximumTopK,
        private int $maximumMessageCharacters,
    ) {
        if ($maximumTopK < 1 || $maximumMessageCharacters < 1) {
            throw new \InvalidArgumentException('Chatbot validation limits must be positive.');
        }
    }

    public function validateAndNormalize(ChatbotDraft $draft): ChatbotDraft
    {
        if ($draft->schemaVersion !== 1) {
            throw new ValidationException('The chatbot draft schema version is not supported.');
        }

        $instructions = $this->boundedText($draft->systemInstructions, 'System instructions', 1, 12_000);
        $fallback = $this->boundedText($draft->fallbackMessage, 'Fallback message', 1, 1_000);

        if ($draft->retrievalTopK < 1 || $draft->retrievalTopK > $this->maximumTopK) {
            throw new ValidationException(sprintf('Retrieval top-K must be between 1 and %d.', $this->maximumTopK));
        }

        if (!is_finite($draft->minimumSimilarity)
            || $draft->minimumSimilarity < 0.0
            || $draft->minimumSimilarity > 1.0) {
            throw new ValidationException('Minimum similarity must be between 0 and 1.');
        }

        if ($draft->maximumMessageCharacters < 1
            || $draft->maximumMessageCharacters > $this->maximumMessageCharacters) {
            throw new ValidationException(sprintf(
                'Maximum message characters must be between 1 and %d.',
                $this->maximumMessageCharacters,
            ));
        }

        if ($draft->maximumMessagesPerSession < 1 || $draft->maximumMessagesPerSession > 100) {
            throw new ValidationException('Maximum messages per session must be between 1 and 100.');
        }

        if ($draft->idleExpiryMinutes < 5 || $draft->idleExpiryMinutes > 1_440) {
            throw new ValidationException('Idle expiry must be between 5 and 1440 minutes.');
        }

        if ($draft->absoluteExpiryMinutes < $draft->idleExpiryMinutes
            || $draft->absoluteExpiryMinutes > 10_080) {
            throw new ValidationException('Absolute expiry must be at least the idle expiry and no more than 10080 minutes.');
        }

        if (!in_array($draft->retentionDays, self::RETENTION_DAYS, true)) {
            throw new ValidationException('Retention days must be 0, 7, 30, or 90.');
        }

        $privacyUrl = $this->privacyUrl($draft->privacyNoticeUrl);
        $disclosure = $this->boundedText($draft->disclosureText, 'Disclosure text', 1, 500);
        $presentation = $this->presentation($draft->presentation);
        $appearance = $this->appearance($draft->appearance);

        return new ChatbotDraft(
            1,
            max(1, $draft->revision),
            $instructions,
            $fallback,
            $draft->retrievalTopK,
            round($draft->minimumSimilarity, 5),
            $draft->citationsEnabled,
            $draft->maximumMessageCharacters,
            $draft->maximumMessagesPerSession,
            $draft->idleExpiryMinutes,
            $draft->absoluteExpiryMinutes,
            $draft->retentionDays,
            $privacyUrl,
            $disclosure,
            $presentation,
            $appearance,
            $draft->updatedAt,
        );
    }

    /** @param array<string, mixed> $values @return array<string, mixed> */
    private function presentation(array $values): array
    {
        $this->exactKeys($values, self::PRESENTATION_KEYS, 'presentation');
        $questions = $values['suggested_questions'] ?? null;

        if (!is_array($questions) || !array_is_list($questions) || count($questions) > 6) {
            throw new ValidationException('Suggested questions must be a list containing at most 6 items.');
        }

        $normalizedQuestions = [];

        foreach ($questions as $question) {
            if (!is_string($question)) {
                throw new ValidationException('Each suggested question must be text.');
            }

            $normalizedQuestions[] = $this->plainText($question, 'Suggested question', 1, 200);
        }

        return [
            'display_name' => $this->plainString($values, 'display_name', 'Display name', 1, 100),
            'welcome_message' => $this->plainString($values, 'welcome_message', 'Welcome message', 1, 1_000),
            'input_placeholder' => $this->plainString($values, 'input_placeholder', 'Input placeholder', 1, 160),
            'suggested_questions' => $normalizedQuestions,
        ];
    }

    /** @param array<string, mixed> $values @return array<string, mixed> */
    private function appearance(array $values): array
    {
        $this->exactKeys($values, self::APPEARANCE_KEYS, 'appearance');
        $accent = $this->plainString($values, 'accent', 'Accent', 7, 7);

        if (preg_match('/\A#[0-9A-Fa-f]{6}\z/', $accent) !== 1) {
            throw new ValidationException('Accent must be a six-digit hexadecimal color.');
        }

        return [
            'accent' => strtoupper($accent),
            'theme' => $this->enumString($values, 'theme', ['light', 'dark']),
            'position' => $this->enumString($values, 'position', ['left', 'right']),
            'launcher_label' => $this->plainString($values, 'launcher_label', 'Launcher label', 1, 50),
            'launcher_icon' => $this->enumString($values, 'launcher_icon', ['chat', 'bubble', 'help']),
            'panel_title' => $this->plainString($values, 'panel_title', 'Panel title', 1, 100),
            'size' => $this->enumString($values, 'size', ['compact', 'standard']),
        ];
    }

    /** @param array<string, mixed> $values @param list<string> $expected */
    private function exactKeys(array $values, array $expected, string $section): void
    {
        $keys = array_keys($values);
        sort($keys);
        $expectedKeys = $expected;
        sort($expectedKeys);

        if ($keys !== $expectedKeys) {
            throw new ValidationException(sprintf('The chatbot %s settings have unsupported or missing fields.', $section));
        }
    }

    /** @param array<string, mixed> $values */
    private function plainString(array $values, string $key, string $label, int $minimum, int $maximum): string
    {
        $value = $values[$key] ?? null;

        if (!is_string($value)) {
            throw new ValidationException(sprintf('%s must be text.', $label));
        }

        return $this->plainText($value, $label, $minimum, $maximum);
    }

    private function plainText(string $value, string $label, int $minimum, int $maximum): string
    {
        $value = $this->boundedText($value, $label, $minimum, $maximum);

        if (strip_tags($value) !== $value) {
            throw new ValidationException(sprintf('%s must not contain HTML.', $label));
        }

        return $value;
    }

    private function boundedText(string $value, string $label, int $minimum, int $maximum): string
    {
        $value = trim($value);
        $length = mb_strlen($value);

        if ($length < $minimum || $length > $maximum || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value) === 1) {
            throw new ValidationException(sprintf('%s must contain between %d and %d valid characters.', $label, $minimum, $maximum));
        }

        return $value;
    }

    private function privacyUrl(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        $url = trim($url);

        if (mb_strlen($url) > 2_048
            || filter_var($url, FILTER_VALIDATE_URL) === false
            || strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            throw new ValidationException('Privacy notice URL must be a valid HTTPS URL.');
        }

        return $url;
    }

    /** @param array<string, mixed> $values @param list<string> $allowed */
    private function enumString(array $values, string $key, array $allowed): string
    {
        $value = $values[$key] ?? null;

        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new ValidationException(sprintf('%s is not an allowed value.', ucfirst(str_replace('_', ' ', $key))));
        }

        return $value;
    }
}

