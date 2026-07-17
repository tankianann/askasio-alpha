<?php

declare(strict_types=1);

namespace App\Ingestion;

use App\Domain\Ingestion\IngestionJob;

interface IngestionProcessorInterface
{
    public function isAvailable(): bool;

    public function process(IngestionJob $job): void;
}
