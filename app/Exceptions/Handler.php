<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Throwable;

class Handler extends ExceptionHandler
{
    
    protected $dontReport = [
        
    ];

    
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];


    public function register()
    {
        $this->reportable(function (Throwable $e) {
            // Без SENTRY_LARAVEL_DSN в .env пакет не биндит 'sentry' в контейнер —
            // на local/без DSN это no-op, ошибки просто уходят в storage/logs как раньше.
            if (app()->bound('sentry')) {
                app('sentry')->captureException($e);
            }
        });
    }
}
