<?php

return [
    'default' => env('BROADCAST_CONNECTION', 'reverb'),

    'connections' => [
        'reverb' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY') ?: 'local',
            'secret' => env('REVERB_APP_SECRET') ?: 'local',
            'app_id' => env('REVERB_APP_ID') ?: 'local',
            'options' => [
                'host' => env('REVERB_HOST') ?: '127.0.0.1',
                'port' => env('REVERB_PORT', 8080),
                'scheme' => env('REVERB_SCHEME') ?: 'http',
                'useTLS' => (env('REVERB_SCHEME') ?: 'http') === 'https',
            ],
        ],

        'pusher' => [
            'driver' => 'pusher',
            'key' => env('PUSHER_APP_KEY') ?: env('REVERB_APP_KEY') ?: 'local',
            'secret' => env('PUSHER_APP_SECRET') ?: env('REVERB_APP_SECRET') ?: 'local',
            'app_id' => env('PUSHER_APP_ID') ?: env('REVERB_APP_ID') ?: 'local',
            'options' => [
                'host' => env('PUSHER_HOST') ?: '127.0.0.1',
                'port' => env('PUSHER_PORT', 6001),
                'scheme' => env('PUSHER_SCHEME') ?: 'http',
                'encrypted' => (env('PUSHER_SCHEME') ?: 'http') === 'https',
                'useTLS' => (env('PUSHER_SCHEME') ?: 'http') === 'https',
            ],
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],
    ],
];
