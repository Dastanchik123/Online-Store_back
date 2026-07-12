<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

class TrustProxies extends Middleware
{
    // Fly.io терминирует TLS на своём edge-прокси и проксирует запросы
    // к приложению по HTTP — без доверия ко всем прокси Laravel не видит
    // X-Forwarded-Proto и генерирует ссылки (asset(), url()) со схемой http.
    protected $proxies = '*';

    
    protected $headers =
        Request::HEADER_X_FORWARDED_FOR |
        Request::HEADER_X_FORWARDED_HOST |
        Request::HEADER_X_FORWARDED_PORT |
        Request::HEADER_X_FORWARDED_PROTO |
        Request::HEADER_X_FORWARDED_AWS_ELB;
}
