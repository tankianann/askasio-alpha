<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Ingestion\JobStatus;
use App\Ingestion\IngestionException;
use App\Ingestion\IngestionWorker;
use App\Services\Ingestion\IngestionQueue;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Tests\Fakes\FakeIngestionProcessor;
use Tests\Fakes\InMemoryIngestionJobRepository;

final class IngestionWorkerTest extends TestCase
{
    public function testItClaimsProcessesAndCompletesOneJob(): void
    {
        $repository = new InMemoryIngestionJobRepository();
        $queue = new IngestionQueue($repository, 3, 30, 3600, 900);
        $queue->enqueue(42);
        $processor = new FakeIngestionProcessor();
        $worker = new IngestionWorker($queue, $processor, new NullLogger(), 'worker-1', 1);

        self::assertTrue($worker->runOnce());
        self::assertSame(1, $processor->processed);
        self::assertSame(JobStatus::Completed, $repository->job(1)->status);
        self::assertSame(1, $repository->job(1)->attempts);
    }

    public function testItReleasesRetryableFailureBackToPending(): void
    {
        $repository = new InMemoryIngestionJobRepository();
        $queue = new IngestionQueue($repository, 3, 30, 3600, 900);
        $queue->enqueue(42);
        $processor = new FakeIngestionProcessor(true, new IngestionException('Temporary extraction failure.'));
        $worker = new IngestionWorker($queue, $processor, new NullLogger(), 'worker-1', 1);

        self::assertTrue($worker->runOnce());
        self::assertSame(JobStatus::Pending, $repository->job(1)->status);
        self::assertSame(30, $repository->lastRetryDelay);
    }

    public function testUnavailableProcessorNeverClaimsAJob(): void
    {
        $repository = new InMemoryIngestionJobRepository();
        $queue = new IngestionQueue($repository, 3, 30, 3600, 900);
        $queue->enqueue(42);
        $worker = new IngestionWorker(
            $queue,
            new FakeIngestionProcessor(false),
            new NullLogger(),
            'worker-1',
            1,
        );

        self::assertFalse($worker->runOnce());
        self::assertSame(0, $repository->claimCalls);
        self::assertSame(JobStatus::Pending, $repository->job(1)->status);
    }

    public function testUnexpectedProcessorFailureEscapesSoTheSupervisorCanRestartTheWorker(): void
    {
        $repository = new InMemoryIngestionJobRepository();
        $queue = new IngestionQueue($repository, 3, 30, 3600, 900);
        $queue->enqueue(42);
        $worker = new IngestionWorker(
            $queue,
            new FakeIngestionProcessor(true, new RuntimeException('Database connection was lost.')),
            new NullLogger(),
            'worker-1',
            1,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Database connection was lost.');

        try {
            $worker->runOnce();
        } finally {
            self::assertSame(JobStatus::Processing, $repository->job(1)->status);
            self::assertNull($repository->lastRetryDelay);
        }
    }

    public function testQueueCompletionFailureEscapesInsteadOfBeingRecordedAsADocumentFailure(): void
    {
        $repository = new InMemoryIngestionJobRepository();
        $repository->completeFailure = new RuntimeException('Database write failed.');
        $queue = new IngestionQueue($repository, 3, 30, 3600, 900);
        $queue->enqueue(42);
        $processor = new FakeIngestionProcessor();
        $worker = new IngestionWorker($queue, $processor, new NullLogger(), 'worker-1', 1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Database write failed.');

        try {
            $worker->runOnce();
        } finally {
            self::assertSame(1, $processor->processed);
            self::assertSame(JobStatus::Processing, $repository->job(1)->status);
        }
    }

    public function testRecoveryFailureEscapesBeforeAJobIsClaimed(): void
    {
        $repository = new InMemoryIngestionJobRepository();
        $repository->recoveryFailure = new RuntimeException('Database unavailable.');
        $queue = new IngestionQueue($repository, 3, 30, 3600, 900);
        $queue->enqueue(42);
        $worker = new IngestionWorker($queue, new FakeIngestionProcessor(), new NullLogger(), 'worker-1', 1);

        $this->expectException(RuntimeException::class);

        try {
            $worker->runOnce();
        } finally {
            self::assertSame(0, $repository->claimCalls);
            self::assertSame(JobStatus::Pending, $repository->job(1)->status);
        }
    }
}
