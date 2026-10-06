<?php

use Illuminate\Support\Facades\Route;

// SPA-фронт (Nuxt) собирается в public/ командой `npm run build:laravel`
// из репозитория Online-Store_front; index.html — это предрендеренная
// именно главная страница (с её контентом внутри HTML), а 200.html —
// пустой SPA-шелл для всех остальных маршрутов, которых нет среди
// статически предрендеренных (/admin, /cashier, /self-service и т.п.).
// Раньше оба случая отдавали index.html — на таких маршрутах в браузере
// до гидратации Vue мелькал контент ГЛАВНОЙ страницы, что выглядело как
// "перебрасывает на главную".
$spaIndex = fn () => file_exists(public_path('index.html'))
    ? response()->file(public_path('index.html'))
    : view('welcome');

$spaFallback = fn () => file_exists(public_path('200.html'))
    ? response()->file(public_path('200.html'))
    : $spaIndex();

Route::get('/', $spaIndex);

Route::get('/healthz', function () {
    return response()->json(['status' => 'ok']);
});

// Все SPA-маршруты (/admin, /catalog, ...) обслуживает фронтовый роутер
Route::fallback(function () use ($spaFallback) {
    if (request()->is('api/*') || request()->is('broadcasting/*')) {
        abort(404);
    }

    return $spaFallback();
});
