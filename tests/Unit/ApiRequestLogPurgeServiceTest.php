<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Api\ApiRequestLog;
use App\Domain\Api\ApiRequestLogPurgeCriteria;
use App\Domain\Api\ApiRequestLogPurgeScope;
use App\Services\Api\ApiRequestLogPurgeService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Fakes\InMemoryApiRequestLogRepository;
use Tests\Fakes\InMemoryMaintenanceLock;

final class ApiRequestLogPurgeServiceTest extends TestCase
{
    public function testItPurgesTheReviewedSnapshotInBatchesWithoutDeletingNewRecords(): void
    {
        $logs = new InMemoryApiRequestLogRepository();

        for ($index = 1; $index <= 5; ++$index) {
            $logs->record($this->log('reviewed-' . $index));
        }

        $lock = new InMemoryMaintenanceLock();
        $service = new ApiRequestLogPurgeService($logs, $lock, new NullLogger(), 2, 'test:purge');
        $snapshot = $service->preview(new ApiRequestLogPurgeCriteria(ApiRequestLogPurgeScope::All));
        $logs->record($this->log('new-after-review'));

        $result = $service->purge($snapshot, 9);

        self::assertSame(5, $snapshot->recordCount);
        self::assertTrue($result->lockAcquired);
        self::assertSame(5, $result->deletedRecords);
        self::assertSame(3, $result->batches);
        self::assertSame(['new-after-review'], array_map(
            static fn (ApiRequestLog $log): string => $log->requestId,
            $logs->logs,
        ));
        self::assertFalse($lock->held);
        self::assertSame(1, $lock->releaseCount);
    }

    public function testItSkipsDeletionWhenAnotherPurgeHoldsTheLock(): void
    {
        $logs = new InMemoryApiRequestLogRepository();
        $logs->record($this->log('retained'));
        $lock = new InMemoryMaintenanceLock();
        $lock->available = false;
        $service = new ApiRequestLogPurgeService($logs, $lock, new NullLogger(), 1000, 'test:purge');
        $snapshot = $service->preview(new ApiRequestLogPurgeCriteria(ApiRequestLogPurgeScope::All));

        $result = $service->purge($snapshot, 9);

        self::assertFalse($result->lockAcquired);
        self::assertSame(0, $result->deletedRecords);
        self::assertCount(1, $logs->logs);
        self::assertSame(0, $lock->releaseCount);
    }

    private function log(string $requestId): ApiRequestLog
    {
        return new ApiRequestLog($requestId, null, '', 'POST', '/api/v1/chat', 200, 20, null, [], '2026-07-01 00:00:00');
    }
}
