#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Database\Connection;
use App\Logging\LoggerFactory;
use App\Maintenance\ChatbotConversationMaintenanceLock;
use App\Maintenance\PdoAdvisoryLock;
use App\Repositories\PdoChatbotConversationRepository;
use App\Services\Chatbots\ChatbotConversationRetentionService;
use App\Support\Config;
use Dotenv\Dotenv;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
Dotenv::createImmutable($root)->safeLoad();
$config = Config::load($root . '/config');
$connection = new Connection($config);
$service = new ChatbotConversationRetentionService(
    new PdoChatbotConversationRepository($connection), new PdoAdvisoryLock($connection),
    (new LoggerFactory())->create($config), $config->requireInt('api.chatbot_conversation_purge_batch_size'),
    ChatbotConversationMaintenanceLock::name($config->requireString('database.database')),
);
$result = $service->runScheduled(new DateTimeImmutable('now', new DateTimeZone('UTC')));
fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR) . PHP_EOL);
exit($result['lock_acquired'] ? 0 : 2);
