<?php

declare(strict_types=1);

use App\Support\Env;

return [
    'max_attempts' => Env::int('JOB_MAX_ATTEMPTS', 3),
    'retry_base_seconds' => Env::int('JOB_RETRY_BASE_SECONDS', 30),
    'retry_maximum_seconds' => Env::int('JOB_RETRY_MAX_SECONDS', 3600),
    'abandoned_timeout_minutes' => Env::int('JOB_ABANDONED_TIMEOUT_MINUTES', 30),
    'poll_seconds' => Env::int('JOB_POLL_SECONDS', 2),
];
