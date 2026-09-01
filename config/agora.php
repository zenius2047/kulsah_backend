<?php

return [
    'enabled' => (bool) env('AGORA_ENABLED', false),
    'app_id' => env('AGORA_APP_ID'),
    'app_certificate' => env('AGORA_APP_CERTIFICATE'),
    'token_ttl' => (int) env('AGORA_TOKEN_TTL', 3600),
    'publisher_token_ttl' => (int) env('AGORA_PUBLISHER_TOKEN_TTL', 900),
    'viewer_token_ttl' => (int) env('AGORA_VIEWER_TOKEN_TTL', 900),
    'recording_enabled' => (bool) env('AGORA_RECORDING_ENABLED', false),
    'customer_id' => env('AGORA_CUSTOMER_ID'),
    'customer_secret' => env('AGORA_CUSTOMER_SECRET'),
    'region' => env('AGORA_REGION', 'eu'),
    'recording_timeout' => (int) env('AGORA_RECORDING_TIMEOUT', 15),
    'recording_resource_expiry_hours' => (int) env('AGORA_RECORDING_RESOURCE_EXPIRY_HOURS', 24),
    'recording_storage_vendor' => (int) env('AGORA_RECORDING_STORAGE_VENDOR', 1),
    'recording_storage_region' => env('AGORA_RECORDING_STORAGE_REGION'),
    'recording_storage_bucket' => env('AGORA_RECORDING_STORAGE_BUCKET'),
    'recording_storage_access_key' => env('AGORA_RECORDING_STORAGE_ACCESS_KEY'),
    'recording_storage_secret_key' => env('AGORA_RECORDING_STORAGE_SECRET_KEY'),
    'recording_storage_prefix' => env('AGORA_RECORDING_STORAGE_PREFIX', 'kulsah/live'),
];