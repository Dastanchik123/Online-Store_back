<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/healthz', function () {
    return response()->json(['status' => 'ok']);
});

Route::get('/init-db', function () {
    \Illuminate\Support\Facades\Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
    return 'Database initialized!';
});
