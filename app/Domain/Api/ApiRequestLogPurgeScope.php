<?php

declare(strict_types=1);

namespace App\Domain\Api;

enum ApiRequestLogPurgeScope: string
{
    case MatchingFilters = 'matching_filters';
    case BeforeDate = 'before_date';
    case OlderThanAge = 'older_than_age';
    case All = 'all';
}
