<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    
    protected function schedule(Schedule $schedule)
    {
        // Каждый запуск будит serverless-базу Neon, которая засыпает после
        // ~5 минут простоя. Чаще 15 минут не запускаем: иначе база не спит
        // никогда и съедает квоту часов (см. комментарий в Dockerfile).

        // Брошенные неоплаченные QR-заказы (см. ExpireUnpaidOrders)
        $schedule->command('orders:expire-unpaid')->everyFifteenMinutes()->withoutOverlapping();

        // Страховка для RunQueueWorkerOnce: задание, поставленное в очередь
        // в первые секунды после запуска разового воркера (его блокировка
        // ещё держится, а сам он уже закончил), иначе ждало бы следующего
        // задания. Раз в час разбираем всё, что осталось, и выходим.
        $schedule->command('queue:work --stop-when-empty --tries=2 --timeout=180')
            ->hourly()
            ->withoutOverlapping()
            ->runInBackground();

        // Дозаполнение индекса поиска по фото: новые фото индексируются сразу
        // при сохранении товара, но если сервис отпечатков в тот момент был
        // недоступен — фото пропускалось. Уже проиндексированные команда
        // пропускает, так что без пропусков сервис даже не будится.
        $schedule->command('products:index-images')
            ->dailyAt('04:30')
            ->withoutOverlapping(120)
            ->runInBackground()
            ->when(fn () => app(\App\Services\ImageSearch\ImageSearchService::class)->isEnabled());
    }

    
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
