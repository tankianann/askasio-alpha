<?php

declare(strict_types=1);

namespace App\Ingestion;

use App\Domain\Ingestion\IngestionJob;
use LogicException;

final class PipelineUnavailableProcessor implements IngestionProcessorInterface
{
    public function isAvailable(): bool
    {
        return false;
    }

    public function process(IngestionJob $job): void
    {
        throw new LogicException('The extraction pipeline is not configured.');
    }
}
