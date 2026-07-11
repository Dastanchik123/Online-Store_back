<?php

use Illuminate\Support\Facades\Route;

// SPA-фронт (Nuxt) собирается в public/ командой `npm run build:laravel`
// из репозитория Online-Store_front; index.html — его точка входа
$spaIndex = fn () => file_exists(public_path('index.html'))
    ? response()->file(public_path('index.html'))
    : view('welcome');

Route::get('/', $spaIndex);

Route::get('/healthz', function () {
    return response()->json(['status' => 'ok']);
});

// Все SPA-маршруты (/admin, /catalog, ...) обслуживает фронтовый роутер
Route::fallback(function () use ($spaIndex) {
    if (request()->is('api/*') || request()->is('broadcasting/*')) {
        abort(404);
    }

    return $spaIndex();
});
