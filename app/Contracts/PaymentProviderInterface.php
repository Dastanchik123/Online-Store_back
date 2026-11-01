<?php

namespace App\Contracts;

use App\Models\Order;
use App\Models\Payment;

/**
 * Абстракция платёжного провайдера (QR-оплата). Боевой — GoPayPaymentProvider,
 * для local/testing без ключей — TemporaryQrPaymentProvider (биндинг в
 * AppServiceProvider). Сервисы заказов про конкретного провайдера не знают.
 */
interface PaymentProviderInterface
{
    // Итог отмены: QR больше не примет оплату
    public const CANCEL_CANCELLED = 'cancelled';

    // Итог отмены: отменить нельзя — банк уже провёл оплату, деньги получены
    public const CANCEL_ALREADY_PAID = 'paid';

    /**
     * Инициировать оплату заказа. Возвращает данные для экрана оплаты:
     * ['payment_id' => string, 'provider' => string, 'status' => string,
     *  'qr_value' => string, 'expires_at' => string (ISO8601)].
     */
    public function createPayment(Order $order, Payment $payment): array;

    /**
     * Отменить неоплаченный платёж на стороне провайдера. Возвращает
     * CANCEL_CANCELLED или CANCEL_ALREADY_PAID.
     *
     * @throws \App\Exceptions\PaymentProviderUnavailableException провайдер
     *         сейчас не ответил — состояние неизвестно, повторить позже
     */
    public function cancelPayment(Payment $payment): string;

    // Состояние платежа у провайдера (fetchStatus)
    public const STATUS_PAID   = 'paid';
    public const STATUS_OPEN   = 'open';
    public const STATUS_CLOSED = 'closed';

    /**
     * Спросить у провайдера состояние платежа — для кнопки «Проверить оплату»,
     * если уведомление (вебхук) задерживается. STATUS_* или null, если
     * провайдер не ответил или такой проверки не поддерживает.
     */
    public function fetchStatus(Payment $payment): ?string;
}
