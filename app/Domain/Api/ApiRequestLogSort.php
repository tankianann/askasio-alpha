<?php

declare(strict_types=1);

namespace App\Domain\Api;

enum ApiRequestLogSort: string
{
    case Date = 'date';
    case Duration = 'duration';
    case Status = 'status';
    case Endpoint = 'endpoint';
    case Connection = 'connection';
}
