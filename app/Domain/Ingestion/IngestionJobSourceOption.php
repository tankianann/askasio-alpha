<?php

declare(strict_types=1);

namespace App\Domain\Ingestion;

final readonly class IngestionJobSourceOption
{
    public function __construct(public int $id, public string $name)
    {
    }
}
