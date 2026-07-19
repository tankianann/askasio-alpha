<?php

declare(strict_types=1);

namespace App\Domain\Api;

enum ApiRequestAuthenticationState: string
{
    case All = 'all';
    case Authenticated = 'authenticated';
    case Unauthenticated = 'unauthenticated';
}
