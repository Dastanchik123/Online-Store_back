<?php

return [

    

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'gemini' => [
        'key' => env('GEMINI_API_KEY'),
    ],

    'gopay' => [
        'base_url'       => env('GOPAY_BASE_URL', 'https://api.gopay.kg'),
        'api_key'        => env('GOPAY_API_KEY'),
        'secret_key'     => env('GOPAY_SECRET_KEY'),
        // Отдельный секрет для проверки подписи вебхуков (Developer → Webhooks
        // в кабинете GoPay) — НЕ совпадает с secret_key от API-запросов, хотя
        // формула подписи (HMAC-SHA512) та же самая.
        'webhook_secret' => env('GOPAY_WEBHOOK_SECRET'),
    ],

];

