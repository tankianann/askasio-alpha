<?php

declare(strict_types=1);

namespace App\Domain\ApiKeys;

enum ApiKeyListSort: string
{
    case Created = 'created';
    case Name = 'name';
    case Status = 'status';
    case LastUsed = 'last_used';
    case Expires = 'expires';
}
