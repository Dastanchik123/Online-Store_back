<?php

namespace App\Contracts;

use App\Models\Order;
use App\Models\Payment;

/**
 * Абстракция платёжного провайдера для self-service кассы. Сегодня
 * реализована временным QR (TemporaryQrPaymentProvider); когда появится
 * настоящий банк/платёжный шлюз, заменяется одной строкой биндинга в
 * AppServiceProvider — SelfServiceOrderService и фронт про конкретного
 * провайдера ничего не знают.
 */
interface PaymentProviderInterface
{
    /**
     * Инициировать оплату заказа. Возвращает данные для экрана оплаты:
     * ['payment_id' => string, 'provider' => string, 'status' => string,
     *  'qr_value' => string, 'expires_at' => string (ISO8601)].
     */
    public function createPayment(Order $order, Payment $payment): array;

    /** Отменить неоплаченный платёж на стороне провайдера (если применимо). */
    public function cancelPayment(Payment $payment): void;
}
