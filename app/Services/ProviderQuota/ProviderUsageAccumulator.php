<?php

declare(strict_types=1);

namespace App\Services\ProviderQuota;

final class ProviderUsageAccumulator
{
    private int $embeddingTokens = 0;

    public function recordEmbeddingTokens(int $tokens): void
    {
        if ($tokens > 0) {
            $this->embeddingTokens += $tokens;
        }
    }

    public function embeddingTokens(): int
    {
        return $this->embeddingTokens;
    }
}
