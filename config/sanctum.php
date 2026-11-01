<?php

return [

    'stateful'   => explode(',', env('SANCTUM_STATEFUL_DOMAINS', sprintf(
        '%s%s',
        'localhost,localhost:3000,127.0.0.1,127.0.0.1:8000,::1',
        env('APP_URL') ? ',' . parse_url(env('APP_URL'), PHP_URL_HOST) : ''
    ))),
    'guard'      => ['web'],
    // Минуты жизни токена. Раньше null — украденный токен действовал вечно.
    // 30 дней по умолчанию: касса может долго работать офлайн без перелогина.
    'expiration' => (int) env('SANCTUM_EXPIRATION', 60 * 24 * 30) ?: null,
    'middleware' => [
        'verify_csrf_token' => App\Http\Middleware\VerifyCsrfToken::class,
        'encrypt_cookies'   => App\Http\Middleware\EncryptCookies::class,
    ],
];
