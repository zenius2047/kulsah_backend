<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    'firebase' => [
        'credentials' => env('GOOGLE_APPLICATION_CREDENTIALS', env('FIREBASE_CREDENTIALS', 'storage/firebase/firebase-service-account.json')),
    ],

    'cloudinary' => [
        'cloud_name' => env('CLOUDINARY_CLOUD_NAME'),
        'api_key' => env('CLOUDINARY_API_KEY'),
        'api_secret' => env('CLOUDINARY_API_SECRET'),
        'folder' => env('CLOUDINARY_FOLDER', 'kulsah/videos'),
        'webhook_url' => env('CLOUDINARY_WEBHOOK_URL'),
    ],

    'audius' => [
        'base_url' => env('AUDIUS_BASE_URL', 'https://api.audius.co/v1'),
        'api_key' => env('AUDIUS_API_KEY'),
        'bearer_token' => env('AUDIUS_BEARER_TOKEN', env('AUDIUS_API_TOKEN')),
        'timeout' => (float) env('AUDIUS_TIMEOUT', 8),
        'connect_timeout' => (float) env('AUDIUS_CONNECT_TIMEOUT', 3),
        'retries' => (int) env('AUDIUS_RETRIES', 2),
        'cache_enabled' => env('AUDIUS_CACHE_ENABLED', true),
        'cache_ttl' => (int) env('AUDIUS_CACHE_TTL', 300),
    ],

    'fastapi' => [
        'url' => env('FASTAPI_URL', 'http://127.0.0.1:8001'),
        'enabled' => env('FASTAPI_ENABLED', true),
        'timeout' => env('FASTAPI_TIMEOUT_SECONDS', 2),
        'retries' => env('FASTAPI_RETRIES', 0),
        'shared_secret' => env('FASTAPI_SHARED_SECRET'),
        'signature_ttl' => env('FASTAPI_SIGNATURE_TTL_SECONDS', 300),
    ],

    'qr_code' => [
        'generator_url' => env('QR_CODE_GENERATOR_URL', 'https://api.qrserver.com/v1/create-qr-code/'),
    ],

];

