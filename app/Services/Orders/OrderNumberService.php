<?php

namespace App\Services\Orders;

use App\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * Номера чеков: одна сквозная серия на канал — P-… касса, S-… касса
 * самообслуживания. Номер берётся из счётчика под блокировкой строки, поэтому
 * два одновременных чека не получат один номер, а при откате продажи номер
 * не «сгорает» (счётчик откатывается вместе с ней).
 *
 * Вызывать внутри транзакции продажи.
 */
class OrderNumberService
{
    public const PREFIXES = [
        Order::CHANNEL_POS          => 'P',
        Order::CHANNEL_SELF_SERVICE => 'S',
    ];

    public function next(string $channel): string
    {
        $prefix = self::PREFIXES[$channel] ?? null;
        if (! $prefix) {
            throw new \InvalidArgumentException("Нет серии номеров для канала {$channel}");
        }

        return DB::transaction(function () use ($prefix) {
            DB::table('order_counters')->insertOrIgnore([
                'prefix'      => $prefix,
                'last_number' => 0,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);

            $current = (int) DB::table('order_counters')->where('prefix', $prefix)->lockForUpdate()->value('last_number');
            $next    = $current + 1;

            DB::table('order_counters')->where('prefix', $prefix)->update([
                'last_number' => $next,
                'updated_at'  => now(),
            ]);

            return "{$prefix}-{$next}";
        });
    }
}
