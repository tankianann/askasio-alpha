<?php

declare(strict_types=1);

use App\Support\Env;

return [
    'session_name' => Env::string('SESSION_NAME', 'rag_admin_session'),
    'session_idle_minutes' => Env::int('SESSION_IDLE_MINUTES', 120),
    'session_regenerate_minutes' => Env::int('SESSION_REGENERATE_MINUTES', 15),
    'session_secure_cookie' => strtolower((string) Env::string('SESSION_SECURE_COOKIE', 'auto')),
    'login_max_attempts' => Env::int('LOGIN_MAX_ATTEMPTS', 5),
    'login_window_minutes' => Env::int('LOGIN_WINDOW_MINUTES', 15),
];
