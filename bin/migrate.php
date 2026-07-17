<?php

declare(strict_types=1);

use App\Database\Connection;
use App\Database\Migrator;
use App\Support\Config;
use Dotenv\Dotenv;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
Dotenv::createImmutable($root)->safeLoad();

try {
    $config = Config::load($root . '/config');
    $connection = new Connection($config);
    $migrator = new Migrator($connection->pdo(), $root . '/database/migrations');
    $executed = $migrator->migrate();

    if ($executed === []) {
        fwrite(STDOUT, "No pending migrations.\n");
        exit(0);
    }

    foreach ($executed as $migration) {
        fwrite(STDOUT, sprintf("Migrated: %s\n", $migration));
    }
} catch (Throwable $exception) {
    fwrite(STDERR, sprintf("Migration failed: %s\n", $exception->getMessage()));
    exit(1);
}
