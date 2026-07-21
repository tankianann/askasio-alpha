<?php

declare(strict_types=1);

namespace App\Domain\ProviderQuota;

final readonly class AiUsageReport
{
    /**
     * @param list<array{access_method: string, usage_tokens: int, estimated_tokens: int, requests: int}> $byAccessMethod
     * @param list<array{day: string, usage_tokens: int, estimated_tokens: int}> $daily
     * @param list<array{id: int|null, name: string, usage_tokens: int, estimated_tokens: int}> $byChatbot
     * @param list<array{id: int|null, name: string, usage_tokens: int, estimated_tokens: int}> $byGeneralApiKey
     * @param list<array{id: int|null, name: string, usage_tokens: int, estimated_tokens: int}> $byChatbotApiKey
     */
    public function __construct(
        public int $usageTokens,
        public int $estimatedTokens,
        public int $requests,
        public int $todayRecordedTokens,
        public int $monthRecordedTokens,
        public array $byAccessMethod,
        public array $daily,
        public array $byChatbot,
        public array $byGeneralApiKey,
        public array $byChatbotApiKey,
    ) {
    }
}
