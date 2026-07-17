<?php

declare(strict_types=1);

namespace App\Domain\Sources;

enum ProcessingStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';
    case Inactive = 'inactive';
}
