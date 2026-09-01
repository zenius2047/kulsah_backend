<?php

return [
    'enabled' => env('API_RESPONSE_CACHE_ENABLED', true),
    'ttl_seconds' => (int) env('API_RESPONSE_CACHE_TTL_SECONDS', 60),
    'prefix' => env('API_RESPONSE_CACHE_PREFIX', 'api-response'),
];
