<?php

namespace App\Services\Payments;

use App\Contracts\PaymentProviderInterface;
use App\Models\Order;
use App\Models\Payment;

/**
 * QR-платежи заказа через провайдера (GoPay) для любого канала: онлайн-заказ,
 * касса самообслуживания, касса (QR-часть смешанной оплаты).
 *
 * Вызывать ВНЕ транзакции БД: обращение к провайдеру — внешний HTTP-запрос,
 * внутри транзакции он держал бы блокировки строк, а при откате оставлял бы
 * у провайдера платёж, о котором мы не знаем. Проведение оплаты по факту —
 * в PaymentSettlementService.
 */
class OrderQrPaymentService
{
    public function __construct(
        private PaymentProviderInterface $paymentProvider,
        private PaymentSettlementService $settlement,
    ) {
    }

    /**
     * Создать QR-платёж. $amount — сумма QR-части (по умолчанию весь заказ).
     * Если провайдер не ответил, платёж помечается failed и исключение
     * пробрасывается дальше.
     */
    public function createQrPayment(Order $order, ?float $amount = null, string $paymentMethod = 'mbank'): Payment
    {
        $payment = Payment::create([
            'order_id'       => $order->id,
            'payment_method' => $paymentMethod,
            'status'         => 'pending',
            'amount'         => $amount ?? $order->total,
            'currency'       => $order->currency ?: 'SOM',
        ]);

        try {
            $payload = $this->paymentProvider->createPayment($order, $payment);
        } catch (\Throwable $e) {
            $payment->update(['status' => 'failed']);
            throw $e;
        }

        $payment->update([
            'transaction_id'  => $payload['payment_id'],
            'payment_details' => json_encode($payload),
        ]);

        return $payment->fresh();
    }

    /**
     * Отменить у провайдера все неоплаченные QR заказа, чтобы по ним больше
     * нельзя было заплатить (перед отменой заказа или выпуском нового QR).
     *
     * Возвращает false, если какой-то QR уже успели оплатить — такая оплата
     * сразу проводится через PaymentSettlementService.
     *
     * @throws \App\Exceptions\PaymentProviderUnavailableException
     */
    public function cancelPendingPayments(Order $order): bool
    {
        $allCancelled = true;

        foreach ($order->payments()->whereIn('status', ['pending', 'processing'])->get() as $payment) {
            $outcome = $this->paymentProvider->cancelPayment($payment);

            if ($outcome === PaymentProviderInterface::CANCEL_ALREADY_PAID) {
                $this->settlement->settleCommitted($payment->transaction_id);
                $allCancelled = false;
                continue;
            }

            // Только если вебхук не успел провести этот платёж
            Payment::whereKey($payment->id)
                ->whereIn('status', ['pending', 'processing'])
                ->update(['status' => 'failed']);
        }

        return $allCancelled;
    }
}
