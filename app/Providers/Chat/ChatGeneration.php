<?php

declare(strict_types=1);

namespace App\Providers\Chat;

final class ChatGeneration
{
    public function __construct(
        public readonly string $answer,
        public readonly ?string $providerResponseId,
        public readonly string $model,
        public readonly int $inputTokens,
        public readonly int $cachedInputTokens,
        public readonly int $outputTokens,
        public readonly int $reasoningTokens,
        public readonly int $totalTokens,
    ) {
    }

    /** @return array<string, int> */
    public function usage(): array
    {
        return [
            'input_tokens' => $this->inputTokens,
            'cached_input_tokens' => $this->cachedInputTokens,
            'output_tokens' => $this->outputTokens,
            'reasoning_tokens' => $this->reasoningTokens,
            'total_tokens' => $this->totalTokens,
        ];
    }
}
