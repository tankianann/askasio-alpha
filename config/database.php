<?php

declare(strict_types=1);

use App\Support\Env;

return [
    'host' => Env::string('DB_HOST', '127.0.0.1'),
    'port' => Env::int('DB_PORT', 3306),
    'database' => Env::string('DB_DATABASE', 'rag_app'),
    'username' => Env::string('DB_USERNAME', 'rag_user'),
    'password' => Env::string('DB_PASSWORD', ''),
    'charset' => 'utf8mb4',
];
