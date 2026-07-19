<?php

declare(strict_types=1);

use App\Support\Env;

return [
    'rate_limit_window_seconds' => Env::int('API_RATE_LIMIT_WINDOW_SECONDS', 60),
    'rate_limit_per_key' => Env::int('API_RATE_LIMIT_PER_KEY', 60),
    'rate_limit_per_ip' => Env::int('API_RATE_LIMIT_PER_IP', 120),
    'chat_rate_limit_per_key' => Env::int('API_CHAT_RATE_LIMIT_PER_KEY', 10),
    'chat_rate_limit_per_ip' => Env::int('API_CHAT_RATE_LIMIT_PER_IP', 20),
    'request_log_retention_days' => Env::int('API_REQUEST_LOG_RETENTION_DAYS', 30),
    'request_log_purge_batch_size' => Env::int('API_REQUEST_LOG_PURGE_BATCH_SIZE', 1000),
];
