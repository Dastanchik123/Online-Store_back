<?php

namespace App\Services\Payments;

use App\Contracts\PaymentProviderInterface;
use App\Events\OrderStatusUpdated;
use App\Models\FinancialTransaction;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * QR-оплата обычных (не self-service) заказов через GoPay. В отличие от
 * SelfServiceOrderService, остаток товара здесь уже списан при создании
 * заказа (OrderController::store) — confirmFromGopay только подтверждает
 * оплату и ведёт бухгалтерию.
 */
class OrderQrPaymentService
{
    public function __construct(private PaymentProviderInterface $paymentProvider)
    {
    }

    public function createQrPayment(Order $order): Payment
    {
        $payment = Payment::create([
            'order_id'       => $order->id,
            'payment_method' => 'mbank',
            'status'         => 'pending',
            'amount'         => $order->total,
            'currency'       => $order->currency ?: 'SOM',
        ]);

        $payload = $this->paymentProvider->createPayment($order, $payment);

        $payment->update([
            'transaction_id'  => $payload['payment_id'],
            'payment_details' => json_encode($payload),
        ]);

        return $payment->fresh();
    }

    /**
     * Вызывается вебхуком GoPay (payment.committed) для заказов, созданных
     * не через self-service кассу (GoPayWebhookController сам определяет,
     * какой сервис вызвать, по channel заказа).
     */
    public function confirmFromGopay(string $gopayPaymentId): void
    {
        $order = DB::transaction(function () use ($gopayPaymentId) {
            $payment = Payment::where('transaction_id', $gopayPaymentId)->lockForUpdate()->first();
            if (! $payment || $payment->status === 'completed') {
                return null;
            }

            $order = Order::where('id', $payment->order_id)->lockForUpdate()->first();
            if (! $order || $order->payment_status === 'paid') {
                return null;
            }

            $payment->update(['status' => 'completed', 'paid_at' => now()]);

            // Банк подтвердил оплату после того, как заказ был отменён —
            // остаток под него уже возвращён на склад, повторно списывать не
            // трогаем. Деньги помечены оплаченными, дальше решает менеджер.
            if ($order->status === 'cancelled') {
                return null;
            }

            $order->syncPaymentStatus();

            if ($order->status === 'pending') {
                $order->update(['status' => 'processing']);
            }

            FinancialTransaction::create([
                'user_id'        => $order->user_id,
                'type'           => 'income',
                'amount'         => $payment->amount,
                'category'       => 'sale',
                'trackable_type' => Order::class,
                'trackable_id'   => $order->id,
                'description'    => "Оплата заказа #{$order->order_number} через QR (GoPay)",
                'payment_method' => 'mbank',
            ]);

            return $order;
        });

        if ($order) {
            try {
                event(new OrderStatusUpdated($order->fresh()));
            } catch (\Throwable $e) {
                Log::warning('Broadcast failed (OrderStatusUpdated): ' . $e->getMessage());
            }
        }
    }
}
