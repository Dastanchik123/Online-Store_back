<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie', 'broadcasting/auth'],
    'allowed_methods' => ['*'],
    'allowed_origins' => [
        'http://192.168.2.176:3000',
        'http://localhost:3000',
        'https://kurulush-store.vercel.app',
        'https://online-store-hyu-ta.fly.dev',
        env('CORS_ALLOWED_ORIGINS', '*'),
    ],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true,
];
