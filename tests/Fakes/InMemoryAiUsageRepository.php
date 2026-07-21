<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Domain\ProviderQuota\AiUsageQuery;
use App\Domain\ProviderQuota\AiUsageReport;
use App\Repositories\AiUsageRepositoryInterface;
use DateTimeImmutable;

final readonly class InMemoryAiUsageRepository implements AiUsageRepositoryInterface
{
    /** @param array<int, array{today: int, month: int, answered_messages: int}> $chatbotApiKeyUsage */
    public function __construct(
        private AiUsageReport $usageReport,
        private array $chatbotApiKeyUsage = [],
    ) {
    }

    public function report(AiUsageQuery $query, DateTimeImmutable $now): AiUsageReport
    {
        return $this->usageReport;
    }

    public function chatbotApiKeyUsage(array $credentialIds, DateTimeImmutable $now): array
    {
        return array_intersect_key($this->chatbotApiKeyUsage, array_flip($credentialIds));
    }
}
