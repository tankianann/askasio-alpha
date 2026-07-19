<?php

declare(strict_types=1);

namespace App\Domain\Api;

enum ApiRequestStatusGroup: string
{
    case Success = 'success';
    case ClientError = 'client_error';
    case ServerError = 'server_error';

    public function minimumStatus(): int
    {
        return match ($this) {
            self::Success => 200,
            self::ClientError => 400,
            self::ServerError => 500,
        };
    }

    public function maximumStatus(): int
    {
        return match ($this) {
            self::Success => 299,
            self::ClientError => 499,
            self::ServerError => 599,
        };
    }
}
