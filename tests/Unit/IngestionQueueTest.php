<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Ingestion\JobStatus;
use App\Ingestion\IngestionException;
use App\Ingestion\PermanentIngestionException;
use App\Services\Ingestion\IngestionQueue;
use PHPUnit\Framework\TestCase;
use Tests\Fakes\InMemoryIngestionJobRepository;

final class IngestionQueueTest extends TestCase
{
    public function testItEnqueuesWithConfiguredAttemptsAndAppliesExponentialRetry(): void
    {
        $repository = new InMemoryIngestionJobRepository();
        $queue = $this->queue($repository);
        $pending = $queue->enqueue(42, 10);
        $claimed = $queue->claim('worker-1');

        self::assertSame(3, $pending->maxAttempts);
        self::assertSame(10, $pending->priority);
        self::assertNotNull($claimed);
        self::assertSame(1, $claimed->attempts);
        self::assertSame(JobStatus::Pending, $queue->retryOrFail(
            $claimed,
            'worker-1',
            new IngestionException('Provider timed out.'),
        ));
        self::assertSame(30, $repository->lastRetryDelay);
        self::assertSame('Provider timed out.', $repository->lastPersistedError);

        $claimedAgain = $queue->claim('worker-1');
        self::assertNotNull($claimedAgain);
        $queue->retryOrFail($claimedAgain, 'worker-1', new IngestionException('Provider timed out again.'));
        self::assertSame(60, $repository->lastRetryDelay);
    }

    public function testPermanentErrorFailsWithoutUsingRemainingAttempts(): void
    {
        $repository = new InMemoryIngestionJobRepository();
        $queue = $this->queue($repository);
        $queue->enqueue(42);
        $job = $queue->claim('worker-1');
        self::assertNotNull($job);

        $status = $queue->retryOrFail($job, 'worker-1', new PermanentIngestionException('Unsupported document.'));

        self::assertSame(JobStatus::Failed, $status);
        self::assertSame(JobStatus::Failed, $repository->job($job->id)->status);
        self::assertSame('Unsupported document.', $repository->lastPersistedError);
    }

    public function testExhaustedAttemptFailsAndControlledMessagesAreRedacted(): void
    {
        $repository = new InMemoryIngestionJobRepository();
        $queue = new IngestionQueue($repository, 1, 30, 3600, 900);
        $queue->enqueue(42);
        $job = $queue->claim('worker-1');
        self::assertNotNull($job);

        $status = $queue->retryOrFail(
            $job,
            'worker-1',
            new IngestionException('Provider rejected Bearer sk-abcdefghijklmnop'),
        );

        self::assertSame(JobStatus::Failed, $status);
        self::assertStringNotContainsString('sk-abcdefghijklmnop', (string) $repository->lastPersistedError);
        self::assertStringContainsString('[REDACTED]', (string) $repository->lastPersistedError);
    }

    public function testUnknownErrorsDoNotPersistSensitiveExceptionMessages(): void
    {
        $repository = new InMemoryIngestionJobRepository();
        $queue = $this->queue($repository);
        $queue->enqueue(42);
        $job = $queue->claim('worker-1');
        self::assertNotNull($job);

        $queue->retryOrFail($job, 'worker-1', new \RuntimeException('Authorization: Bearer secret-token'));

        self::assertStringNotContainsString('secret-token', (string) $repository->lastPersistedError);
        self::assertStringContainsString('Review the application log', (string) $repository->lastPersistedError);
    }

    public function testItDelegatesAbandonedReservationRecoveryWithConfiguredTimeout(): void
    {
        $repository = new InMemoryIngestionJobRepository();
        $repository->recoveryResult = ['retried' => 2, 'failed' => 1];
        $queue = $this->queue($repository);

        self::assertSame(['retried' => 2, 'failed' => 1], $queue->recoverAbandoned());
        self::assertSame(900, $repository->lastRecoveryTimeout);
    }

    private function queue(InMemoryIngestionJobRepository $repository): IngestionQueue
    {
        return new IngestionQueue($repository, 3, 30, 3600, 900);
    }
}
