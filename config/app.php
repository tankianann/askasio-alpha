<?php

declare(strict_types=1);

use App\Support\Env;

return [
    'env' => Env::string('APP_ENV', 'production'),
    'debug' => Env::bool('APP_DEBUG', false),
    'url' => Env::string('APP_URL', 'http://localhost:8080'),
    'secret' => Env::string('APP_SECRET'),
    'timezone' => Env::string('APP_TIMEZONE', 'UTC'),
    'filesystem_path' => Env::string('FILESYSTEM_PATH', 'storage/sources'),
    'max_upload_size_mb' => Env::int('MAX_UPLOAD_SIZE_MB', 20),
];
