<?php

declare(strict_types=1);

namespace App\RAG;

use App\Domain\RAG\GeneratedAnswer;
use App\Providers\Chat\ChatMalformedResponseException;
use App\Providers\Chat\ChatProviderInterface;

final class AnswerGenerator
{
    public const INSUFFICIENT_INFORMATION = 'The provided sources do not contain enough information to answer this question.';

    public function __construct(
        private readonly Retriever $retriever,
        private readonly ContextSelector $context,
        private readonly PromptBuilder $prompts,
        private readonly ChatProviderInterface $chat,
    ) {
    }

    /** @param array<string, mixed> $options */
    public function generate(string $question, ?int $topK = null, array $options = []): GeneratedAnswer
    {
        return $this->generateConfigured(
            $question,
            $topK,
            [],
            '',
            self::INSUFFICIENT_INFORMATION,
            [],
            $options,
        );
    }

    /**
     * @param array<string, mixed> $retrievalFilters
     * @param list<array{role: 'user'|'assistant', content: string}> $history
     * @param array<string, mixed> $providerOptions
     */
    public function generateConfigured(
        string $question,
        ?int $topK,
        array $retrievalFilters,
        string $chatbotInstructions,
        string $fallbackMessage,
        array $history,
        array $providerOptions = [],
    ): GeneratedAnswer {
        if (trim($fallbackMessage) === '') {
            throw new \InvalidArgumentException('A grounded fallback message is required.');
        }

        $matches = $this->retriever->retrieve($question, $topK, $retrievalFilters);
        $context = $this->context->select($matches);

        if ($context->chunks === []) {
            return new GeneratedAnswer($fallbackMessage, [], [
                'retrieved_chunks' => 0,
                'context_tokens' => 0,
                'input_tokens' => 0,
                'cached_input_tokens' => 0,
                'output_tokens' => 0,
                'reasoning_tokens' => 0,
                'total_tokens' => 0,
            ], null, true);
        }

        $prompt = $this->prompts->build($question, $context->chunks, $chatbotInstructions, $history);
        $generation = $this->chat->generate($prompt->instructions, $prompt->input, $providerOptions);
        $this->validateCitations($generation->answer, count($context->chunks));

        return new GeneratedAnswer($generation->answer, $context->chunks, [
            'retrieved_chunks' => count($context->chunks),
            'context_tokens' => $context->estimatedTokens,
            ...$generation->usage(),
        ], $generation->model, false);
    }

    private function validateCitations(string $answer, int $sourceCount): void
    {
        preg_match_all('/\[S(\d+)\]/', $answer, $matches);
        $references = $matches[1];

        if ($references === []) {
            if (str_contains($answer, self::INSUFFICIENT_INFORMATION)) {
                return;
            }

            throw new ChatMalformedResponseException('The chat provider answer did not include source citations.');
        }

        foreach ($references as $reference) {
            $number = filter_var($reference, FILTER_VALIDATE_INT);

            if ($number === false || $number < 1 || $number > $sourceCount) {
                throw new ChatMalformedResponseException('The chat provider answer contained an invalid source citation.');
            }
        }
    }
}
