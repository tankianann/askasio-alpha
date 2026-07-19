<?php

declare(strict_types=1);

use App\Database\Connection;
use App\Logging\LoggerFactory;
use App\Maintenance\PdoAdvisoryLock;
use App\Repositories\PdoApiRequestLogRepository;
use App\Services\Api\ApiRequestLogRetentionPolicy;
use App\Services\Api\ApiRequestLogRetentionService;
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
$connection = new Connection($config);
$databaseName = $config->requireString('database.database');
$retention = new ApiRequestLogRetentionService(
    new PdoApiRequestLogRepository($connection),
    new ApiRequestLogRetentionPolicy($config->requireInt('api.request_log_retention_days')),
    new PdoAdvisoryLock($connection),
    $logger,
    $config->requireInt('api.request_log_purge_batch_size'),
    'ask-asio:api-retention:' . substr(hash('sha256', $databaseName), 0, 16),
);

return ['api_request_log_retention' => $retention, 'logger' => $logger];
