<?php

declare(strict_types=1);

namespace App\Database;

use App\Support\Config;
use PDO;

final class Connection
{
    private ?PDO $pdo = null;

    public function __construct(private readonly Config $config)
    {
    }

    public function pdo(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $host = $this->config->requireString('database.host');
        $port = $this->config->requireInt('database.port');
        $database = $this->config->requireString('database.database');
        $charset = $this->config->requireString('database.charset');

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $database, $charset);
        $this->pdo = new PDO(
            $dsn,
            $this->config->requireString('database.username'),
            (string) $this->config->get('database.password', ''),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
                PDO::ATTR_PERSISTENT => false,
            ],
        );
        $this->pdo->exec("SET time_zone = '+00:00'");

        return $this->pdo;
    }

    public function ping(): bool
    {
        return $this->pdo()->query('SELECT 1')->fetchColumn() === 1;
    }
}
