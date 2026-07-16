<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie', 'broadcasting/auth'],
    'allowed_methods' => ['*'],
    'allowed_origins' => array_values(array_filter([
        'http://192.168.2.176:3000',
        'https://192.168.2.176:3000',
        'http://192.168.2.183:3000',
        'https://192.168.2.183:3000',
        'http://localhost:3000',
        'https://localhost:3000',
        'https://kurulush-store.vercel.app',
        'https://online-store-hyu-ta.fly.dev',
        // Без явного значения переменной — origin не добавляется (раньше тут был
        // fallback на '*', который вместе с supports_credentials открывал API
        // любому сайту, если переменную забыли выставить на новом деплое)
        env('CORS_ALLOWED_ORIGINS'),
    ])),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    // Кэш preflight в браузере: без него каждый запрос к API
    // сопровождается лишним OPTIONS-раундтрипом до сервера
    'max_age' => 86400,
    'supports_credentials' => true,
];
