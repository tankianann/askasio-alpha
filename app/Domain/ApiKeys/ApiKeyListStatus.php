<?php

declare(strict_types=1);

namespace App\Domain\ApiKeys;

enum ApiKeyListStatus: string
{
    case All = 'all';
    case Active = 'active';
    case Revoked = 'revoked';
    case Expired = 'expired';
}
