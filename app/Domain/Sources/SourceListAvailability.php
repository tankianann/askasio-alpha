<?php

declare(strict_types=1);

namespace App\Domain\Sources;

enum SourceListAvailability: string
{
    case All = 'all';
    case Enabled = 'enabled';
    case Disabled = 'disabled';
    case Deleted = 'deleted';
}
