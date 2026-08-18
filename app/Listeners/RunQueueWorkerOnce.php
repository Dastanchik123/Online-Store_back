<?php

namespace App\Listeners;

use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\Cache;

/**
 * Постоянного queue:work в контейнере нет (см. Dockerfile) — непрерывный
 * опрос БД держал бы Neon (serverless Postgres с автозасыпанием) активным
 * круглосуточно и сжигал бы бесплатную квоту компьюта. Вместо этого при
 * каждой постановке job в очередь запускаем разовый воркер, который
 * обработает всё, что накопилось, и сам завершится.
 *
 * Общий листенер вместо копирования exec() в каждом контроллере, который
 * что-то диспатчит: раньше это было захардкожено только в
 * ReportExportController.
 */
class RunQueueWorkerOnce
{
    public function handle(JobQueued $event): void
    {
        if ($event->connectionName === 'sync') {
            return;
        }

        // Если за один HTTP-запрос диспатчится несколько job (например пачка
        // email-уведомлений), не запускать воркер повторно на каждую —
        // достаточно одного, который выгребет всю очередь целиком.
        if (! Cache::add('queue-worker-launch-lock', true, 5)) {
            return;
        }

        // Абсолютный путь к php-cli, а не просто "php": php-fpm по умолчанию
        // чистит окружение (clear_env), PATH может быть недоступен дочернему
        // процессу, запущенному через exec().
        $artisan = escapeshellarg(base_path('artisan'));
        exec("/usr/local/bin/php {$artisan} queue:work --stop-when-empty --tries=2 --timeout=180 > /dev/null 2>&1 &");
    }
}
