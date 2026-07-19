<?php

declare(strict_types=1);

use App\Support\Env;

return [
    'embedding_provider' => Env::string('EMBEDDING_PROVIDER', 'openai'),
    'chat_provider' => Env::string('LLM_PROVIDER', 'openai'),
    'openai' => [
        'api_key' => Env::string('OPENAI_API_KEY'),
        'base_url' => Env::string('OPENAI_BASE_URL', 'https://api.openai.com'),
        'embedding_model' => Env::string('OPENAI_EMBEDDING_MODEL'),
        'embedding_dimensions' => Env::nullableInt('OPENAI_EMBEDDING_DIMENSIONS'),
        'chat_model' => Env::string('OPENAI_CHAT_MODEL'),
        'chat_reasoning_effort' => Env::string('OPENAI_CHAT_REASONING_EFFORT'),
        'chat_maximum_output_tokens' => Env::int('OPENAI_CHAT_MAX_OUTPUT_TOKENS', 600),
        'chat_maximum_retries' => Env::int('OPENAI_CHAT_MAXIMUM_RETRIES', 0),
        'connect_timeout_seconds' => Env::int('OPENAI_CONNECT_TIMEOUT_SECONDS', 10),
        'request_timeout_seconds' => Env::int('OPENAI_REQUEST_TIMEOUT_SECONDS', 60),
        'maximum_retries' => Env::int('OPENAI_MAXIMUM_RETRIES', 3),
    ],
];
