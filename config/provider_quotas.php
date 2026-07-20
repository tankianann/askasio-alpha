<?php

declare(strict_types=1);

use App\Support\Env;

return [
    'global_daily_tokens' => Env::int('PROVIDER_GLOBAL_DAILY_TOKEN_LIMIT', 1_000_000),
    'global_monthly_tokens' => Env::int('PROVIDER_GLOBAL_MONTHLY_TOKEN_LIMIT', 10_000_000),
    'api_key_daily_tokens' => Env::int('PROVIDER_API_KEY_DAILY_TOKEN_LIMIT', 100_000),
    'api_key_monthly_tokens' => Env::int('PROVIDER_API_KEY_MONTHLY_TOKEN_LIMIT', 1_000_000),
    'reservation_ttl_seconds' => Env::int('PROVIDER_QUOTA_RESERVATION_TTL_SECONDS', 900),
];
