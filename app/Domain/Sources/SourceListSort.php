<?php

declare(strict_types=1);

namespace App\Domain\Sources;

enum SourceListSort: string
{
    case Updated = 'updated';
    case Name = 'name';
    case Type = 'type';
    case Availability = 'availability';
    case Processing = 'processing';
    case Revisions = 'revisions';
}
