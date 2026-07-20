<?php

declare(strict_types=1);

namespace App\Services\Chatbots;

use App\Domain\Chatbots\ChatbotDraft;
use App\Exceptions\ValidationException;
use App\Http\Request;

final class ChatbotAdminFormParser
{
    public function draft(Request $request, int $revision): ChatbotDraft
    {
        return new ChatbotDraft(
            1,
            $revision,
            $this->string($request, 'system_instructions'),
            $this->string($request, 'fallback_message'),
            $this->integer($request, 'retrieval_top_k'),
            $this->decimal($request, 'minimum_similarity'),
            $request->input('citations_enabled') === '1',
            $this->integer($request, 'maximum_message_characters'),
            $this->integer($request, 'maximum_messages_per_session'),
            $this->integer($request, 'idle_expiry_minutes'),
            $this->integer($request, 'absolute_expiry_minutes'),
            $this->integer($request, 'retention_days'),
            $this->nullableString($request, 'privacy_notice_url'),
            $this->string($request, 'disclosure_text'),
            [
                'display_name' => $this->string($request, 'display_name'),
                'welcome_message' => $this->string($request, 'welcome_message'),
                'input_placeholder' => $this->string($request, 'input_placeholder'),
                'suggested_questions' => $this->lines($request, 'suggested_questions'),
            ],
            [
                'accent' => $this->string($request, 'accent'),
                'theme' => $this->string($request, 'theme'),
                'position' => $this->string($request, 'position'),
                'launcher_label' => $this->string($request, 'launcher_label'),
                'launcher_icon' => $this->string($request, 'launcher_icon'),
                'panel_title' => $this->string($request, 'panel_title'),
                'size' => $this->string($request, 'size'),
            ],
        );
    }

    /** @return list<string> */
    public function origins(Request $request): array
    {
        return $this->lines($request, 'origins');
    }

    public function expectedRevision(Request $request): int
    {
        return $this->integer($request, 'revision');
    }

    /** @return array<string, mixed> */
    public function old(Request $request): array
    {
        $fields = [
            'name', 'description', 'system_instructions', 'fallback_message', 'retrieval_top_k',
            'minimum_similarity', 'citations_enabled', 'maximum_message_characters',
            'maximum_messages_per_session', 'idle_expiry_minutes', 'absolute_expiry_minutes',
            'retention_days', 'privacy_notice_url', 'disclosure_text', 'display_name',
            'welcome_message', 'input_placeholder', 'suggested_questions', 'accent', 'theme',
            'position', 'launcher_label', 'launcher_icon', 'panel_title', 'size', 'origins',
        ];
        $old = [];

        foreach ($fields as $field) {
            $value = $request->input($field);

            if (is_string($value)) {
                $old[$field] = $value;
            }
        }

        $old['citations_enabled'] = $request->input('citations_enabled') === '1' ? '1' : '';

        return $old;
    }

    private function string(Request $request, string $field): string
    {
        $value = $request->input($field);

        if (!is_string($value)) {
            throw new ValidationException(sprintf('%s must be text.', $this->label($field)));
        }

        return $value;
    }

    private function nullableString(Request $request, string $field): ?string
    {
        $value = $this->string($request, $field);

        return trim($value) === '' ? null : $value;
    }

    private function integer(Request $request, string $field): int
    {
        $value = $request->input($field);
        $integer = is_string($value) ? filter_var($value, FILTER_VALIDATE_INT) : false;

        if (!is_int($integer)) {
            throw new ValidationException(sprintf('%s must be a whole number.', $this->label($field)));
        }

        return $integer;
    }

    private function decimal(Request $request, string $field): float
    {
        $value = $request->input($field);

        if (!is_string($value) || !is_numeric($value)) {
            throw new ValidationException(sprintf('%s must be a number.', $this->label($field)));
        }

        return (float) $value;
    }

    /** @return list<string> */
    private function lines(Request $request, string $field): array
    {
        $value = $this->string($request, $field);
        $lines = preg_split('/\R/u', $value);

        if (!is_array($lines)) {
            throw new ValidationException(sprintf('%s could not be read.', $this->label($field)));
        }

        return array_values(array_filter(
            array_map('trim', $lines),
            static fn (string $line): bool => $line !== '',
        ));
    }

    private function label(string $field): string
    {
        return ucfirst(str_replace('_', ' ', $field));
    }
}
