<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;
use App\Domain\Api\ApiAccessMethod;
use App\Domain\ProviderQuota\ProviderQuotaAttribution;
use App\Domain\ProviderQuota\ProviderQuotaReservation;
use App\Exceptions\ProviderQuotaExceededException;
use DateTimeImmutable;
use PDO;
use RuntimeException;
use Throwable;

final class PdoProviderQuotaRepository implements ProviderQuotaRepositoryInterface
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function reserve(
        int $apiKeyId,
        string $operation,
        int $tokens,
        array $limits,
        DateTimeImmutable $now,
        DateTimeImmutable $expiresAt,
        ?ProviderQuotaAttribution $attribution = null,
    ): ProviderQuotaReservation {
        if ($apiKeyId < 0 || !in_array($operation, ['retrieve', 'chat'], true) || $tokens < 1
            || ($apiKeyId === 0 && $operation !== 'chat')) {
            throw new \InvalidArgumentException('Provider quota reservation input is invalid.');
        }

        $daily = $now->format('Y-m-d');
        $monthly = $now->format('Y-m-01');
        $windows = $this->windows($apiKeyId, $daily, $monthly, $limits);
        $pdo = $this->connection->pdo();
        $pdo->beginTransaction();

        try {
            $insertBucket = $pdo->prepare(
                'INSERT IGNORE INTO provider_quota_buckets (
                    scope, identifier_id, period_type, period_start, consumed_tokens, reserved_tokens,
                    created_at, updated_at
                 ) VALUES (:scope, :identifier_id, :period_type, :period_start, 0, 0, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))',
            );
            $lockBucket = $pdo->prepare(
                'SELECT consumed_tokens, reserved_tokens FROM provider_quota_buckets
                 WHERE scope = :scope AND identifier_id = :identifier_id
                   AND period_type = :period_type AND period_start = :period_start
                 FOR UPDATE',
            );
            $reserveBucket = $pdo->prepare(
                'UPDATE provider_quota_buckets
                 SET reserved_tokens = reserved_tokens + :tokens, updated_at = UTC_TIMESTAMP(6)
                 WHERE scope = :scope AND identifier_id = :identifier_id
                   AND period_type = :period_type AND period_start = :period_start',
            );

            foreach ($windows as $window) {
                $parameters = $this->windowParameters($window);
                $insertBucket->execute($parameters);
                $lockBucket->execute($parameters);
                $row = $lockBucket->fetch();

                if (!is_array($row)) {
                    throw new RuntimeException('A provider quota bucket could not be locked.');
                }

                $allocated = (int) $row['consumed_tokens'] + (int) $row['reserved_tokens'];

                if ($window['limit'] > 0 && $allocated + $tokens > $window['limit']) {
                    throw new ProviderQuotaExceededException(sprintf(
                        'The %s %s provider token budget has been exhausted.',
                        str_replace('_', ' ', $window['scope']),
                        $window['period_type'],
                    ));
                }
            }

            foreach ($windows as $window) {
                $reserveBucket->execute([
                    ...$this->windowParameters($window),
                    'tokens' => $tokens,
                ]);
            }

            $id = bin2hex(random_bytes(16));
            $attribution ??= new ProviderQuotaAttribution(
                $apiKeyId > 0 ? ApiAccessMethod::GeneralApiKey : ApiAccessMethod::Unauthenticated,
                apiKeyId: $apiKeyId > 0 ? $apiKeyId : null,
            );
            $reservation = $pdo->prepare(
                'INSERT INTO provider_quota_reservations (
                    id, api_key_id, access_method, chatbot_api_key_id, chatbot_id,
                    operation, reserved_tokens, actual_tokens,
                    daily_period_start, monthly_period_start, status, expires_at, created_at
                 ) VALUES (
                    :id, :api_key_id, :access_method, :chatbot_api_key_id, :chatbot_id,
                    :operation, :reserved_tokens, NULL,
                    :daily_period_start, :monthly_period_start, \'active\', :expires_at, UTC_TIMESTAMP(6)
                 )',
            );
            $reservation->execute([
                'id' => $id,
                'api_key_id' => $apiKeyId,
                'access_method' => $attribution->accessMethod->value,
                'chatbot_api_key_id' => $attribution->chatbotApiKeyId,
                'chatbot_id' => $attribution->chatbotId,
                'operation' => $operation,
                'reserved_tokens' => $tokens,
                'daily_period_start' => $daily,
                'monthly_period_start' => $monthly,
                'expires_at' => $expiresAt->format('Y-m-d H:i:s.u'),
            ]);
            $pdo->commit();

            return new ProviderQuotaReservation($id, $apiKeyId, $operation, $tokens, $attribution);
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }
    }

    public function reconcile(string $reservationId, int $actualTokens, bool $estimated = false): void
    {
        if ($actualTokens < 0) {
            throw new \InvalidArgumentException('Actual provider token usage must not be negative.');
        }

        $pdo = $this->connection->pdo();
        $pdo->beginTransaction();

        try {
            $statement = $pdo->prepare(
                'SELECT id, api_key_id, access_method, chatbot_api_key_id, chatbot_id, operation,
                        reserved_tokens, daily_period_start, monthly_period_start, status, created_at
                 FROM provider_quota_reservations WHERE id = :id FOR UPDATE',
            );
            $statement->execute(['id' => $reservationId]);
            $row = $statement->fetch();

            if (!is_array($row)) {
                $pdo->commit();

                return;
            }

            if ($row['status'] !== 'active') {
                $pdo->commit();

                return;
            }

            $this->reconcileLockedRow($pdo, $row, $actualTokens, $estimated);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }
    }

    public function reconcileExpired(int $limit): int
    {
        $limit = max(1, min($limit, 500));
        $pdo = $this->connection->pdo();
        $pdo->beginTransaction();

        try {
            $rows = $pdo->query(
                'SELECT id, api_key_id, access_method, chatbot_api_key_id, chatbot_id, operation,
                        reserved_tokens, daily_period_start, monthly_period_start, status, created_at
                 FROM provider_quota_reservations
                 WHERE status = \'active\' AND expires_at < UTC_TIMESTAMP(6)
                 ORDER BY expires_at ASC LIMIT ' . $limit . ' FOR UPDATE SKIP LOCKED',
            )->fetchAll();

            foreach ($rows as $row) {
                $this->reconcileLockedRow($pdo, $row, (int) $row['reserved_tokens'], true);
            }

            $pdo->commit();

            return count($rows);
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }
    }

    public function usage(array $apiKeyIds, DateTimeImmutable $now): array
    {
        $apiKeyIds = array_values(array_unique(array_filter($apiKeyIds, static fn (int $id): bool => $id > 0)));
        $conditions = ['(scope = \'global\' AND identifier_id = 0)'];
        $parameters = [
            'daily_start' => $now->format('Y-m-d'),
            'monthly_start' => $now->format('Y-m-01'),
        ];

        if ($apiKeyIds !== []) {
            $placeholders = [];

            foreach ($apiKeyIds as $index => $apiKeyId) {
                $name = 'api_key_' . $index;
                $placeholders[] = ':' . $name;
                $parameters[$name] = $apiKeyId;
            }

            $conditions[] = '(scope = \'api_key\' AND identifier_id IN (' . implode(', ', $placeholders) . '))';
        }

        $statement = $this->connection->pdo()->prepare(
            'SELECT scope, identifier_id, period_type, consumed_tokens, reserved_tokens
             FROM provider_quota_buckets
             WHERE (' . implode(' OR ', $conditions) . ')
               AND ((period_type = \'daily\' AND period_start = :daily_start)
                 OR (period_type = \'monthly\' AND period_start = :monthly_start))',
        );
        $statement->execute($parameters);
        $usage = [];

        foreach ($statement->fetchAll() as $row) {
            $key = $row['scope'] === 'global' ? 'global' : 'api_key:' . (int) $row['identifier_id'];
            $period = (string) $row['period_type'];
            $usage[$key] ??= [
                'daily' => ['consumed' => 0, 'reserved' => 0],
                'monthly' => ['consumed' => 0, 'reserved' => 0],
            ];
            $usage[$key][$period] = [
                'consumed' => (int) $row['consumed_tokens'],
                'reserved' => (int) $row['reserved_tokens'],
            ];
        }

        return $usage;
    }

    public function usageAcrossApiKeys(DateTimeImmutable $now): array
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT period_type, SUM(consumed_tokens) AS consumed_tokens,
                    SUM(reserved_tokens) AS reserved_tokens
             FROM provider_quota_buckets
             WHERE scope = \'api_key\'
               AND ((period_type = \'daily\' AND period_start = :daily_start)
                 OR (period_type = \'monthly\' AND period_start = :monthly_start))
             GROUP BY period_type',
        );
        $statement->execute([
            'daily_start' => $now->format('Y-m-d'),
            'monthly_start' => $now->format('Y-m-01'),
        ]);
        $usage = [
            'daily' => ['consumed' => 0, 'reserved' => 0],
            'monthly' => ['consumed' => 0, 'reserved' => 0],
        ];

        foreach ($statement->fetchAll() as $row) {
            $period = (string) $row['period_type'];
            $usage[$period] = [
                'consumed' => (int) $row['consumed_tokens'],
                'reserved' => (int) $row['reserved_tokens'],
            ];
        }

        return $usage;
    }

    /**
     * @param array{global_daily: int, global_monthly: int, api_key_daily: int, api_key_monthly: int} $limits
     * @return list<array{scope: string, identifier_id: int, period_type: string, period_start: string, limit: int}>
     */
    private function windows(int $apiKeyId, string $daily, string $monthly, array $limits): array
    {
        $windows = [
            ['scope' => 'global', 'identifier_id' => 0, 'period_type' => 'daily', 'period_start' => $daily, 'limit' => $limits['global_daily']],
            ['scope' => 'global', 'identifier_id' => 0, 'period_type' => 'monthly', 'period_start' => $monthly, 'limit' => $limits['global_monthly']],
        ];

        if ($apiKeyId > 0) {
            $windows[] = ['scope' => 'api_key', 'identifier_id' => $apiKeyId, 'period_type' => 'daily', 'period_start' => $daily, 'limit' => $limits['api_key_daily']];
            $windows[] = ['scope' => 'api_key', 'identifier_id' => $apiKeyId, 'period_type' => 'monthly', 'period_start' => $monthly, 'limit' => $limits['api_key_monthly']];
        }

        return $windows;
    }

    /** @param array{scope: string, identifier_id: int, period_type: string, period_start: string, limit: int} $window @return array<string, int|string> */
    private function windowParameters(array $window): array
    {
        return [
            'scope' => $window['scope'],
            'identifier_id' => $window['identifier_id'],
            'period_type' => $window['period_type'],
            'period_start' => $window['period_start'],
        ];
    }

    /** @param array<string, mixed> $row */
    private function reconcileLockedRow(PDO $pdo, array $row, int $actualTokens, bool $estimated): void
    {
        $reservedTokens = (int) $row['reserved_tokens'];
        $windows = [
            ['scope' => 'global', 'identifier_id' => 0, 'period_type' => 'daily', 'period_start' => (string) $row['daily_period_start']],
            ['scope' => 'global', 'identifier_id' => 0, 'period_type' => 'monthly', 'period_start' => (string) $row['monthly_period_start']],
        ];

        if ((int) $row['api_key_id'] > 0) {
            $windows[] = ['scope' => 'api_key', 'identifier_id' => (int) $row['api_key_id'], 'period_type' => 'daily', 'period_start' => (string) $row['daily_period_start']];
            $windows[] = ['scope' => 'api_key', 'identifier_id' => (int) $row['api_key_id'], 'period_type' => 'monthly', 'period_start' => (string) $row['monthly_period_start']];
        }
        $bucket = $pdo->prepare(
            'UPDATE provider_quota_buckets
             SET reserved_tokens = GREATEST(reserved_tokens - :reserved_tokens, 0),
                 consumed_tokens = consumed_tokens + :actual_tokens,
                 updated_at = UTC_TIMESTAMP(6)
             WHERE scope = :scope AND identifier_id = :identifier_id
               AND period_type = :period_type AND period_start = :period_start',
        );

        foreach ($windows as $window) {
            $bucket->execute([
                ...$window,
                'reserved_tokens' => $reservedTokens,
                'actual_tokens' => $actualTokens,
            ]);

            if ($bucket->rowCount() !== 1) {
                throw new RuntimeException('A provider quota bucket could not be reconciled.');
            }
        }

        $usage = $pdo->prepare(
            'INSERT INTO ai_usage_records (
                reservation_id, access_method, api_key_id, chatbot_api_key_id, chatbot_id,
                operation, usage_tokens, is_estimated, occurred_at, recorded_at
             ) VALUES (
                :reservation_id, :access_method, :api_key_id, :chatbot_api_key_id, :chatbot_id,
                :operation, :usage_tokens, :is_estimated, :occurred_at, UTC_TIMESTAMP(6)
             )',
        );
        $usage->execute([
            'reservation_id' => $row['id'],
            'access_method' => $row['access_method'],
            'api_key_id' => (int) $row['api_key_id'] > 0 ? (int) $row['api_key_id'] : null,
            'chatbot_api_key_id' => isset($row['chatbot_api_key_id']) ? (int) $row['chatbot_api_key_id'] : null,
            'chatbot_id' => isset($row['chatbot_id']) ? (int) $row['chatbot_id'] : null,
            'operation' => $row['operation'],
            'usage_tokens' => $actualTokens,
            'is_estimated' => $estimated ? 1 : 0,
            'occurred_at' => $row['created_at'],
        ]);

        $reservation = $pdo->prepare(
            'DELETE FROM provider_quota_reservations WHERE id = :id AND status = \'active\'',
        );
        $reservation->execute([
            'id' => $row['id'],
        ]);

        if ($reservation->rowCount() !== 1) {
            throw new RuntimeException('A provider quota reservation could not be finalized.');
        }
    }
}
