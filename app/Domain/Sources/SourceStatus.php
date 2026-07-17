<?php

declare(strict_types=1);

namespace App\Domain\Sources;

enum SourceStatus: string
{
    case Enabled = 'enabled';
    case Disabled = 'disabled';
}
