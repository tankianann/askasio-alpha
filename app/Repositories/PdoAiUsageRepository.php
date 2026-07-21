<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;
use App\Domain\ProviderQuota\AiUsageQuery;
use App\Domain\ProviderQuota\AiUsageReport;
use DateTimeImmutable;

final readonly class PdoAiUsageRepository implements AiUsageRepositoryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function report(AiUsageQuery $query, DateTimeImmutable $now): AiUsageReport
    {
        $pdo = $this->connection->pdo();
        $parameters = ['from' => $query->fromUtc, 'before' => $query->beforeUtc];
        $summary = $pdo->prepare(
            'SELECT COALESCE(SUM(usage_tokens), 0) AS usage_tokens,
                    COALESCE(SUM(CASE WHEN is_estimated = 1 THEN usage_tokens ELSE 0 END), 0) AS estimated_tokens,
                    COUNT(*) AS request_count
             FROM ai_usage_records WHERE occurred_at >= :from AND occurred_at < :before',
        );
        $summary->execute($parameters);
        $summaryRow = $summary->fetch();

        if (!is_array($summaryRow)) {
            throw new \RuntimeException('AI usage summary could not be loaded.');
        }

        $periods = $pdo->prepare(
            'SELECT
                COALESCE(SUM(CASE WHEN occurred_at >= :today THEN usage_tokens ELSE 0 END), 0) AS today_tokens,
                COALESCE(SUM(CASE WHEN occurred_at >= :month THEN usage_tokens ELSE 0 END), 0) AS month_tokens
             FROM ai_usage_records WHERE occurred_at < :before',
        );
        $periods->execute([
            'today' => $now->format('Y-m-d 00:00:00'),
            'month' => $now->format('Y-m-01 00:00:00'),
            'before' => $now->modify('+1 day')->format('Y-m-d 00:00:00'),
        ]);
        $periodRow = $periods->fetch();

        if (!is_array($periodRow)) {
            throw new \RuntimeException('AI usage periods could not be loaded.');
        }

        $byAccess = $pdo->prepare(
            'SELECT access_method, SUM(usage_tokens) AS usage_tokens,
                    SUM(CASE WHEN is_estimated = 1 THEN usage_tokens ELSE 0 END) AS estimated_tokens,
                    COUNT(*) AS request_count
             FROM ai_usage_records WHERE occurred_at >= :from AND occurred_at < :before
             GROUP BY access_method ORDER BY usage_tokens DESC, access_method ASC',
        );
        $byAccess->execute($parameters);

        $daily = $pdo->prepare(
            'SELECT DATE(occurred_at) AS day, SUM(usage_tokens) AS usage_tokens,
                    SUM(CASE WHEN is_estimated = 1 THEN usage_tokens ELSE 0 END) AS estimated_tokens
             FROM ai_usage_records WHERE occurred_at >= :from AND occurred_at < :before
             GROUP BY DATE(occurred_at) ORDER BY day ASC',
        );
        $daily->execute($parameters);

        return new AiUsageReport(
            (int) $summaryRow['usage_tokens'],
            (int) $summaryRow['estimated_tokens'],
            (int) $summaryRow['request_count'],
            (int) $periodRow['today_tokens'],
            (int) $periodRow['month_tokens'],
            $this->metrics($byAccess->fetchAll(), 'access_method'),
            $this->metrics($daily->fetchAll(), 'day'),
            $this->subjectMetrics(
                'chatbot_id',
                'chatbots',
                'chatbot',
                $parameters,
            ),
            $this->subjectMetrics('api_key_id', 'api_keys', 'general API key', $parameters),
            $this->subjectMetrics(
                'chatbot_api_key_id',
                'chatbot_integration_credentials',
                'chatbot API key',
                $parameters,
            ),
        );
    }

    public function chatbotApiKeyUsage(array $credentialIds, DateTimeImmutable $now): array
    {
        $credentialIds = array_values(array_unique(array_filter($credentialIds, static fn (int $id): bool => $id > 0)));

        if ($credentialIds === []) {
            return [];
        }

        $placeholders = [];
        $parameters = [
            'today' => $now->format('Y-m-d 00:00:00'),
            'month' => $now->format('Y-m-01 00:00:00'),
        ];

        foreach ($credentialIds as $index => $id) {
            $key = 'credential_' . $index;
            $placeholders[] = ':' . $key;
            $parameters[$key] = $id;
        }

        $statement = $this->connection->pdo()->prepare(
            'SELECT chatbot_api_key_id,
                    SUM(CASE WHEN occurred_at >= :today THEN usage_tokens ELSE 0 END) AS today_tokens,
                    SUM(CASE WHEN occurred_at >= :month THEN usage_tokens ELSE 0 END) AS month_tokens,
                    SUM(CASE WHEN operation = \'chat\' AND is_estimated = 0 THEN 1 ELSE 0 END) AS answered_messages
             FROM ai_usage_records
             WHERE chatbot_api_key_id IN (' . implode(', ', $placeholders) . ')
             GROUP BY chatbot_api_key_id',
        );
        $statement->execute($parameters);
        $usage = [];

        foreach ($statement->fetchAll() as $row) {
            $usage[(int) $row['chatbot_api_key_id']] = [
                'today' => (int) $row['today_tokens'],
                'month' => (int) $row['month_tokens'],
                'answered_messages' => (int) $row['answered_messages'],
            ];
        }

        return $usage;
    }

    /** @param list<array<string, mixed>> $rows @return list<array<string, int|string>> */
    private function metrics(array $rows, string $label): array
    {
        return array_map(static fn (array $row): array => [
            $label => (string) $row[$label],
            'usage_tokens' => (int) $row['usage_tokens'],
            'estimated_tokens' => (int) $row['estimated_tokens'],
            ...isset($row['request_count']) ? ['requests' => (int) $row['request_count']] : [],
        ], $rows);
    }

    /** @param array<string, int|string> $parameters @return list<array{id: int|null, name: string, usage_tokens: int, estimated_tokens: int}> */
    private function subjectMetrics(string $column, string $table, string $fallback, array $parameters): array
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT usage_records.' . $column . ' AS subject_id,
                    COALESCE(MAX(subjects.name), CONCAT(\'Deleted ' . $fallback . ' #\', usage_records.' . $column . ')) AS subject_name,
                    SUM(usage_records.usage_tokens) AS usage_tokens,
                    SUM(CASE WHEN usage_records.is_estimated = 1 THEN usage_records.usage_tokens ELSE 0 END) AS estimated_tokens
             FROM ai_usage_records usage_records
             LEFT JOIN ' . $table . ' subjects ON subjects.id = usage_records.' . $column . '
             WHERE usage_records.occurred_at >= :from AND usage_records.occurred_at < :before
               AND usage_records.' . $column . ' IS NOT NULL
             GROUP BY usage_records.' . $column . '
             ORDER BY usage_tokens DESC, subject_id ASC LIMIT 100',
        );
        $statement->execute($parameters);

        return array_map(static fn (array $row): array => [
            'id' => isset($row['subject_id']) ? (int) $row['subject_id'] : null,
            'name' => (string) $row['subject_name'],
            'usage_tokens' => (int) $row['usage_tokens'],
            'estimated_tokens' => (int) $row['estimated_tokens'],
        ], $statement->fetchAll());
    }
}
