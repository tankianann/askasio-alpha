<?php

declare(strict_types=1);

use App\Support\Env;

return [
    'rate_limit_window_seconds' => Env::int('API_RATE_LIMIT_WINDOW_SECONDS', 60),
    'rate_limit_per_key' => Env::int('API_RATE_LIMIT_PER_KEY', 60),
    'rate_limit_per_ip' => Env::int('API_RATE_LIMIT_PER_IP', 120),
    'chat_rate_limit_per_key' => Env::int('API_CHAT_RATE_LIMIT_PER_KEY', 10),
    'chat_rate_limit_per_ip' => Env::int('API_CHAT_RATE_LIMIT_PER_IP', 20),
    'public_chatbot_rate_limit_window_seconds' => Env::int('CHATBOT_PUBLIC_RATE_LIMIT_WINDOW_SECONDS', 60),
    'public_chatbot_config_rate_limit_per_ip' => Env::int('CHATBOT_PUBLIC_CONFIG_RATE_LIMIT_PER_IP', 120),
    'public_chatbot_config_rate_limit_per_chatbot' => Env::int('CHATBOT_PUBLIC_CONFIG_RATE_LIMIT_PER_CHATBOT', 600),
    'public_chatbot_session_rate_limit_per_ip' => Env::int('CHATBOT_PUBLIC_SESSION_RATE_LIMIT_PER_IP', 20),
    'public_chatbot_session_rate_limit_per_chatbot' => Env::int('CHATBOT_PUBLIC_SESSION_RATE_LIMIT_PER_CHATBOT', 120),
    'public_chatbot_message_rate_limit_per_ip' => Env::int('CHATBOT_PUBLIC_MESSAGE_RATE_LIMIT_PER_IP', 30),
    'public_chatbot_message_rate_limit_per_chatbot' => Env::int('CHATBOT_PUBLIC_MESSAGE_RATE_LIMIT_PER_CHATBOT', 300),
    'public_chatbot_message_rate_limit_per_session' => Env::int('CHATBOT_PUBLIC_MESSAGE_RATE_LIMIT_PER_SESSION', 20),
    'public_chatbot_pending_timeout_seconds' => Env::int('CHATBOT_PUBLIC_PENDING_TIMEOUT_SECONDS', 120),
    'request_log_retention_days' => Env::int('API_REQUEST_LOG_RETENTION_DAYS', 30),
    'request_log_purge_batch_size' => Env::int('API_REQUEST_LOG_PURGE_BATCH_SIZE', 1000),
];
