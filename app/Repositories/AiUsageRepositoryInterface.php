<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\ProviderQuota\AiUsageQuery;
use App\Domain\ProviderQuota\AiUsageReport;
use DateTimeImmutable;

interface AiUsageRepositoryInterface
{
    public function report(AiUsageQuery $query, DateTimeImmutable $now): AiUsageReport;

    /**
     * @param list<int> $credentialIds
     * @return array<int, array{today: int, month: int, answered_messages: int}>
     */
    public function chatbotApiKeyUsage(array $credentialIds, DateTimeImmutable $now): array;
}
