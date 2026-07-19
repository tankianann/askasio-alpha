<?php

declare(strict_types=1);

namespace App\Maintenance;

final class ApiRequestLogMaintenanceLock
{
    public static function name(string $databaseName): string
    {
        if ($databaseName === '') {
            throw new \InvalidArgumentException('The database name is required for the maintenance lock.');
        }

        return 'ask-asio:api-retention:' . substr(hash('sha256', $databaseName), 0, 16);
    }
}
