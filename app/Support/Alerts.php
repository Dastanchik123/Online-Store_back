<?php

namespace App\Support;

use App\Models\SystemAlert;

/**
 * Уведомления персоналу о ситуациях, которые требуют ручного действия
 * (страница «Алерты» в админке, право alerts.manage). Пишутся в той же
 * транзакции, что и операция: откатилась операция — не остаётся и алерта.
 */
class Alerts
{
    // Деньги по QR получены, но товар по ним не отдаётся (двойная оплата,
    // оплата уже отменённого заказа) — вернуть покупателю наличными
    public const PAYMENT_NEEDS_REFUND = 'payment_needs_refund';

    // Сумма в уведомлении GoPay не совпала с суммой платежа
    public const PAYMENT_AMOUNT_MISMATCH = 'payment_amount_mismatch';

    // Уведомление об оплате по платежу, которого у нас нет
    public const PAYMENT_UNKNOWN = 'payment_unknown';

    // Остаток товара ушёл в минус (продан товар, которого нет по учёту)
    public const NEGATIVE_STOCK = 'negative_stock';

    public static function raise(string $type, string $message, array $data = [], string $priority = 'medium'): SystemAlert
    {
        return SystemAlert::create([
            'type'     => $type,
            'message'  => $message,
            'data'     => $data,
            'priority' => $priority,
            'is_read'  => false,
        ]);
    }
}
