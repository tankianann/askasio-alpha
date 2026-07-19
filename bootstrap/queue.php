<?php

declare(strict_types=1);

use App\Database\Connection;
use App\Logging\LoggerFactory;
use App\Repositories\PdoIngestionJobRepository;
use App\Services\Ingestion\IngestionQueue;
use App\Support\Config;
use Dotenv\Dotenv;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
Dotenv::createImmutable($root)->safeLoad();
$config = Config::load($root . '/config');
$timezone = $config->requireString('app.timezone');

if (!in_array($timezone, timezone_identifiers_list(), true)) {
    throw new RuntimeException('APP_TIMEZONE is not a valid timezone identifier.');
}

date_default_timezone_set($timezone);
$logger = (new LoggerFactory())->create($config);
$queue = new IngestionQueue(
    new PdoIngestionJobRepository(new Connection($config)),
    $config->requireInt('queue.max_attempts'),
    $config->requireInt('queue.retry_base_seconds'),
    $config->requireInt('queue.retry_maximum_seconds'),
    $config->requireInt('queue.abandoned_timeout_minutes') * 60,
);

return ['queue' => $queue, 'logger' => $logger];
