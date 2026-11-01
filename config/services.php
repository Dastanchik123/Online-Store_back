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

    // Сервис отпечатков картинок (папка image-embedder, модель CLIP) —
    // поиск товаров, похожих на фото покупателя. Без URL поиск выключен.
    'embedder' => [
        'url'            => env('EMBEDDER_URL'),
        'secret'         => env('EMBEDDER_SECRET'),
        // Должна совпадать с моделью сервиса: при смене модели старые
        // отпечатки не используются и пересчитываются products:index-images
        'model'          => env('EMBEDDER_MODEL', 'Xenova/clip-vit-base-patch32'),
        // Сходство ниже — явно другой товар. Проверено на фото каталога:
        // свой товар на искажённом снимке набирает 0.65–0.94
        'min_similarity' => (float) env('EMBEDDER_MIN_SIMILARITY', 0.6),
        // На одну попытку (их до 3). Холодный старт машины на Fly вместе
        // с загрузкой модели — ~20 с
        'timeout'        => (int) env('EMBEDDER_TIMEOUT', 30),
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

    // SMS-шлюз smspro.nikita.kg — коды входа покупателей по номеру телефона.
    // Без логина/пароля (локально и в тестах) SMS не отправляются, код
    // пишется в storage/logs/laravel.log (App\Services\Sms\LogSmsSender).
    'nikita_sms' => [
        'login'    => env('NIKITA_SMS_LOGIN'),
        'password' => env('NIKITA_SMS_PASSWORD'),
        // Имя отправителя (до 11 латинских символов), одобренное в кабинете шлюза
        'sender'   => env('NIKITA_SMS_SENDER'),
        // Подпись магазина в тексте SMS (иначе — настройка site_name)
        'signature' => env('NIKITA_SMS_SIGNATURE'),
        // true — шлюз проверяет запрос, но SMS не отправляет и не списывает
        'test'     => (bool) env('NIKITA_SMS_TEST', false),
    ],

];

