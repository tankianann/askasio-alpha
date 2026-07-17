<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Domain\Ingestion\IngestionJob;
use App\Ingestion\IngestionProcessorInterface;
use Throwable;

final class FakeIngestionProcessor implements IngestionProcessorInterface
{
    public int $processed = 0;

    public function __construct(
        private readonly bool $available = true,
        private readonly ?Throwable $failure = null,
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function process(IngestionJob $job): void
    {
        $this->processed++;

        if ($this->failure instanceof Throwable) {
            throw $this->failure;
        }
    }
}
