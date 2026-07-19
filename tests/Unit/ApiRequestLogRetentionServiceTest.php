<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Api\ApiRequestLog;
use App\Services\Api\ApiRequestLogRetentionPolicy;
use App\Services\Api\ApiRequestLogRetentionService;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Fakes\InMemoryApiRequestLogRepository;
use Tests\Fakes\InMemoryMaintenanceLock;

final class ApiRequestLogRetentionServiceTest extends TestCase
{
    public function testItDeletesExpiredRecordsInBatchesAndIsIdempotent(): void
    {
        $logs = new InMemoryApiRequestLogRepository();

        for ($index = 1; $index <= 5; ++$index) {
            $logs->logs[] = $this->log('old-' . $index, '2026-01-01 00:00:00.000000');
        }

        $logs->logs[] = $this->log('boundary', '2026-04-20 04:00:00.000000');
        $logs->logs[] = $this->log('current', '2026-07-01 00:00:00.000000');
        $lock = new InMemoryMaintenanceLock();
        $service = $this->service($logs, $lock, 90, 2);

        $first = $service->run();

        self::assertTrue($first->enabled);
        self::assertTrue($first->lockAcquired);
        self::assertSame(5, $first->deletedRecords);
        self::assertSame(3, $first->batches);
        self::assertSame('2026-04-20 04:00:00.000000', $first->cutoffUtc);
        self::assertSame(['boundary', 'current'], array_map(
            static fn (ApiRequestLog $log): string => $log->requestId,
            $logs->logs,
        ));
        self::assertFalse($lock->held);
        self::assertSame(1, $lock->releaseCount);

        $second = $service->run();

        self::assertSame(0, $second->deletedRecords);
        self::assertSame(0, $second->batches);
        self::assertCount(2, $logs->logs);
        self::assertSame(2, $lock->releaseCount);
    }

    public function testKeepForeverSkipsDeletionAndLocking(): void
    {
        $logs = new InMemoryApiRequestLogRepository();
        $logs->logs[] = $this->log('old', '2020-01-01 00:00:00.000000');
        $lock = new InMemoryMaintenanceLock();

        $result = $this->service($logs, $lock, 0, 1000)->run();

        self::assertFalse($result->enabled);
        self::assertFalse($result->lockAcquired);
        self::assertSame(0, $lock->acquisitionAttempts);
        self::assertCount(1, $logs->logs);
    }

    public function testConcurrentRunExitsWithoutDeletingRecords(): void
    {
        $logs = new InMemoryApiRequestLogRepository();
        $logs->logs[] = $this->log('old', '2020-01-01 00:00:00.000000');
        $lock = new InMemoryMaintenanceLock();
        $lock->available = false;

        $result = $this->service($logs, $lock, 30, 1000)->run();

        self::assertTrue($result->enabled);
        self::assertFalse($result->lockAcquired);
        self::assertSame(0, $result->deletedRecords);
        self::assertSame(0, $lock->releaseCount);
        self::assertCount(1, $logs->logs);
    }

    public function testItReleasesTheLockWhenDeletionFails(): void
    {
        $logs = new InMemoryApiRequestLogRepository();
        $logs->pruneFailure = new \RuntimeException('database unavailable');
        $lock = new InMemoryMaintenanceLock();

        try {
            $this->service($logs, $lock, 30, 1000)->run();
            self::fail('The purge failure should have been rethrown.');
        } catch (\RuntimeException $exception) {
            self::assertSame('database unavailable', $exception->getMessage());
        }

        self::assertFalse($lock->held);
        self::assertSame(1, $lock->releaseCount);
    }

    public function testItRejectsAnUnsafeBatchSize(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service(new InMemoryApiRequestLogRepository(), new InMemoryMaintenanceLock(), 30, 10001);
    }

    private function service(
        InMemoryApiRequestLogRepository $logs,
        InMemoryMaintenanceLock $lock,
        int $days,
        int $batchSize,
    ): ApiRequestLogRetentionService {
        return new ApiRequestLogRetentionService(
            $logs,
            new ApiRequestLogRetentionPolicy($days),
            $lock,
            new NullLogger(),
            $batchSize,
            'test:api-retention',
            static fn (): DateTimeImmutable => new DateTimeImmutable('2026-07-19 12:00:00+08:00'),
        );
    }

    private function log(string $requestId, string $createdAt): ApiRequestLog
    {
        return new ApiRequestLog(
            $requestId,
            null,
            '',
            'POST',
            '/api/v1/chat',
            200,
            50,
            null,
            [],
            $createdAt,
        );
    }
}
