<?php

return [
    'frontend_url' => env('ADMIN_CONSOLE_URL', env('APP_URL', 'http://localhost:3000')),
    'super_admin' => [
        'name' => env('SUPER_ADMIN_NAME', 'Kulsah Super Admin'),
        'username' => env('SUPER_ADMIN_USERNAME'),
        'email' => env('SUPER_ADMIN_EMAIL'),
        'password' => env('SUPER_ADMIN_PASSWORD'),
    ],
];
