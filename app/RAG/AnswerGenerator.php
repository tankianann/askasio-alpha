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
        $matches = $this->retriever->retrieve($question, $topK);
        $context = $this->context->select($matches);

        if ($context->chunks === []) {
            return new GeneratedAnswer(self::INSUFFICIENT_INFORMATION, [], [
                'retrieved_chunks' => 0,
                'context_tokens' => 0,
                'input_tokens' => 0,
                'cached_input_tokens' => 0,
                'output_tokens' => 0,
                'reasoning_tokens' => 0,
                'total_tokens' => 0,
            ]);
        }

        $prompt = $this->prompts->build($question, $context->chunks);
        $generation = $this->chat->generate($prompt->instructions, $prompt->input, $options);
        $this->validateCitations($generation->answer, count($context->chunks));

        return new GeneratedAnswer($generation->answer, $context->chunks, [
            'retrieved_chunks' => count($context->chunks),
            'context_tokens' => $context->estimatedTokens,
            ...$generation->usage(),
        ]);
    }

    private function validateCitations(string $answer, int $sourceCount): void
    {
        preg_match_all('/\[S(\d+)\]/', $answer, $matches);
        $references = $matches[1] ?? [];

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
