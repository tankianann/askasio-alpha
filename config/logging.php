<?php

declare(strict_types=1);

use App\Support\Env;

return [
    'level' => Env::string('LOG_LEVEL', 'info'),
    'path' => dirname(__DIR__) . '/storage/logs/application.log',
    'max_files' => 14,
    'log_api_questions' => Env::bool('LOG_API_QUESTIONS', false),
];
