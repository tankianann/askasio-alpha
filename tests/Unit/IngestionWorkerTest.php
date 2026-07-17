<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Ingestion\JobStatus;
use App\Ingestion\IngestionException;
use App\Ingestion\IngestionWorker;
use App\Services\Ingestion\IngestionQueue;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
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
}
