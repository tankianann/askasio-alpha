<?php

declare(strict_types=1);

namespace App\Services\Chatbots;

use App\Domain\Chatbots\ChatbotConversationPurgeSnapshot;
use App\Maintenance\MaintenanceLockInterface;
use App\Repositories\ChatbotConversationRepositoryInterface;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class ChatbotConversationRetentionService
{
    public function __construct(
        private ChatbotConversationRepositoryInterface $conversations,
        private MaintenanceLockInterface $lock,
        private LoggerInterface $logger,
        private int $batchSize,
        private string $lockName,
    ) {
        if ($this->batchSize < 1 || $this->batchSize > 10_000) {
            throw new \InvalidArgumentException('Conversation retention batch size must be between 1 and 10000.');
        }
    }

    /** @return array{lock_acquired: bool, expired: int, purged: int} */
    public function runScheduled(DateTimeImmutable $now): array
    {
        if (!$this->lock->acquire($this->lockName)) {
            $this->logger->notice('Conversation retention skipped because another run holds the lock.');
            return ['lock_acquired' => false, 'expired' => 0, 'purged' => 0];
        }
        $expired = 0;
        $purged = 0;
        try {
            do { $count = $this->conversations->expireDue($now, $this->batchSize); $expired += $count; } while ($count === $this->batchSize);
            do { $count = $this->conversations->purgeEligible($now, $this->batchSize); $purged += $count; } while ($count === $this->batchSize);
            $this->logger->info('Conversation retention completed.', ['expired_sessions' => $expired, 'purged_sessions' => $purged]);
            return ['lock_acquired' => true, 'expired' => $expired, 'purged' => $purged];
        } finally {
            $this->lock->release($this->lockName);
        }
    }

    public function purgeReviewed(ChatbotConversationPurgeSnapshot $snapshot, DateTimeImmutable $now, int $administratorId): int
    {
        if (!$this->lock->acquire($this->lockName)) {
            throw new \RuntimeException('Another conversation retention or purge run is active.');
        }
        $deleted = 0;
        $failure = null;
        try {
            do { $count = $this->conversations->purgeSnapshotBatch($snapshot, $now, $this->batchSize); $deleted += $count; } while ($count === $this->batchSize);
            $this->logger->info('Manual conversation purge completed.', [
                'admin_user_id' => $administratorId,
                'reviewed_sessions' => $snapshot->recordCount,
                'snapshot_maximum_id' => $snapshot->maximumId,
                'deleted_sessions' => $deleted,
                'traffic' => $snapshot->query->isTest === null ? 'all' : ($snapshot->query->isTest ? 'test' : 'production'),
                'chatbot_id' => $snapshot->query->chatbotId,
            ]);
            return $deleted;
        } catch (Throwable $exception) {
            $failure = $exception;
            throw $exception;
        } finally {
            try { $this->lock->release($this->lockName); } catch (Throwable $release) { if (!$failure instanceof Throwable) { throw $release; } }
        }
    }
}
