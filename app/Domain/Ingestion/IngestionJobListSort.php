<?php

declare(strict_types=1);

namespace App\Domain\Ingestion;

enum IngestionJobListSort: string
{
    case Date = 'date';
    case Status = 'status';
    case Source = 'source';
    case Attempts = 'attempts';
    case Available = 'available';
}
