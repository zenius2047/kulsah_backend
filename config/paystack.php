<?php

return [
    'secret_key' => env('PAYSTACK_SECRET_KEY'),
    'public_key' => env('PAYSTACK_PUBLIC_KEY'),
    'base_url' => rtrim(env('PAYSTACK_BASE_URL', 'https://api.paystack.co'), '/'),
    'currency' => strtoupper(env('PAYSTACK_CURRENCY', 'GHS')),
    'callback_url' => env('PAYSTACK_CALLBACK_URL'),
    'channels' => array_values(array_filter(array_map('trim', explode(',', env('PAYSTACK_CHANNELS', 'card,mobile_money'))))),
    'mobile_money_providers' => ['mtn', 'vod', 'tgo'],
    'timeout' => (int) env('PAYSTACK_TIMEOUT', 15),
];
