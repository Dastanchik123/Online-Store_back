<?php

namespace App\Services\Payments;

use App\Events\NewOrderPlaced;
use App\Events\OrderStatusUpdated;
use App\Models\FinancialTransaction;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Services\Debts\DebtPaymentService;
use App\Support\Alerts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Единственное место, где QR-платёж проводится по факту оплаты в банке
 * (вебхук GoPay, итог отмены «уже оплачено», демо-подтверждение) — для
 * любого канала: онлайн-заказ, касса самообслуживания, касса.
 *
 * Правило: деньги, которые банк подтвердил, фиксируются ВСЕГДА. Если заказ к
 * этому моменту уже оплачен другим платежом или отменён, товар по этим деньгам
 * не отдаётся — создаётся алерт «вернуть покупателю наличными».
 */
class PaymentSettlementService
{
    public const CATEGORY_NEEDS_REFUND = 'Оплата QR (требуется возврат)';

    /**
     * Провести оплату по платежу провайдера. Идемпотентно: повторная доставка
     * того же события ничего не задвоит.
     *
     * @return Order|null заказ, если его состояние изменилось
     */
    public function settleCommitted(string $providerPaymentId, ?string $reportedAmount = null): ?Order
    {
        $result = DB::transaction(function () use ($providerPaymentId, $reportedAmount) {
            $payment = Payment::where('transaction_id', $providerPaymentId)->lockForUpdate()->first();

            if (! $payment) {
                Log::error("Settlement: платёж {$providerPaymentId} не найден");
                Alerts::raise(Alerts::PAYMENT_UNKNOWN, "Банк подтвердил оплату {$providerPaymentId}, но такого платежа нет в системе. Проверьте кабинет GoPay.", [
                    'provider_payment_id' => $providerPaymentId,
                    'amount'              => $reportedAmount,
                ], 'high');

                return null;
            }

            if ($payment->status === 'completed') {
                return null;
            }

            if ($reportedAmount !== null && abs((float) $reportedAmount - (float) $payment->amount) > 0.009) {
                // Не проводим заказ на чужую сумму — разбирается человек
                Alerts::raise(Alerts::PAYMENT_AMOUNT_MISMATCH, "Сумма оплаты {$reportedAmount} не совпадает с платежом #{$payment->id} ({$payment->amount}). Проверьте в кабинете GoPay.", [
                    'payment_id' => $payment->id,
                    'order_id'   => $payment->order_id,
                    'reported'   => $reportedAmount,
                    'expected'   => (string) $payment->amount,
                ], 'high');

                return null;
            }

            $order = Order::whereKey($payment->order_id)->lockForUpdate()->firstOrFail();

            $payment->update(['status' => 'completed', 'paid_at' => now()]);

            // QR-погашение долга — выплата по долгу, а не оплата заказа
            if ($payment->customer_debt_id) {
                if (! app(DebtPaymentService::class)->settleQrPayment($payment)) {
                    $this->recordMoneyToRefund($order, $payment, 'долг уже погашен или отменён');
                }

                return null;
            }

            if ($order->payment_status === 'paid' || $order->status === 'cancelled') {
                $this->recordMoneyToRefund($order, $payment);

                return null;
            }

            match ($order->channel) {
                Order::CHANNEL_SELF_SERVICE => $this->completeSelfService($order, $payment),
                Order::CHANNEL_POS          => $this->completePos($order, $payment),
                default                     => $this->completeOnline($order, $payment),
            };

            return $order;
        });

        if ($result) {
            $fresh = $result->fresh();
            try {
                event(new OrderStatusUpdated($fresh));
            } catch (\Throwable $e) {
                Log::warning('Broadcast failed (OrderStatusUpdated): ' . $e->getMessage());
            }

            // Онлайн QR-заказ при оформлении не объявлялся персоналу (см.
            // OrderController::store) — объявляем сейчас, когда он оплачен:
            // тост и автопечать чека на сборку в админке, письмо покупателю
            if ($fresh->channel === Order::CHANNEL_ONLINE && $fresh->payment_method === 'mbank') {
                try {
                    event(new NewOrderPlaced($fresh));
                } catch (\Throwable $e) {
                    Log::warning('Broadcast failed (NewOrderPlaced): ' . $e->getMessage());
                }
            }
        }

        return $result;
    }

    /**
     * Платёж закрыт без оплаты (истёк, отменён, отклонён банком). Заказ здесь
     * не трогаем: онлайн-покупатель может выпустить новый QR, а киоск и касса
     * сами отменяют заказ по своему сценарию.
     */
    public function markClosed(string $providerPaymentId): void
    {
        Payment::where('transaction_id', $providerPaymentId)
            ->whereIn('status', ['pending', 'processing'])
            ->update(['status' => 'failed']);
    }

    // Деньги получены, но товар по ним не отдаётся: учитываем приход (касса
    // должна сходиться с выпиской банка) и просим вернуть деньги наличными.
    // Приход привязан к платежу, а не к заказу — чтобы не попасть в выручку
    // заказа и в расчёт возврата при его отмене.
    private function recordMoneyToRefund(Order $order, Payment $payment, ?string $reason = null): void
    {
        $reason ??= $order->status === 'cancelled' ? 'заказ уже отменён' : 'заказ уже оплачен другим платежом';

        FinancialTransaction::create([
            'user_id'        => null,
            'type'           => 'income',
            'amount'         => $payment->amount,
            'category'       => self::CATEGORY_NEEDS_REFUND,
            'trackable_type' => Payment::class,
            'trackable_id'   => $payment->id,
            'description'    => "QR-оплата по заказу #{$order->order_number}: {$reason}, требуется возврат",
            'payment_method' => 'qr',
        ]);

        Alerts::raise(Alerts::PAYMENT_NEEDS_REFUND, "Покупатель оплатил {$payment->amount} сом по QR, но {$reason} (заказ #{$order->order_number}). Верните деньги наличными.", [
            'payment_id'   => $payment->id,
            'order_id'     => $order->id,
            'order_number' => $order->order_number,
            'amount'       => (string) $payment->amount,
        ], 'high');
    }

    // Киоск: остаток списывается только по факту оплаты. Товар уже у
    // покупателя в руках, поэтому нехватка по учёту оплату не отменяет —
    // остаток уходит в минус и создаётся алерт.
    private function completeSelfService(Order $order, Payment $payment): void
    {
        $this->writeOffStock($order);

        $order->update(['status' => 'delivered', 'payment_status' => 'paid']);

        FinancialTransaction::create([
            'user_id'        => null,
            'type'           => 'income',
            'amount'         => $payment->amount,
            'category'       => 'Продажа товаров (Self-Service - QR)',
            'description'    => "Оплата заказа Self-Service #{$order->order_number}",
            'trackable_type' => Order::class,
            'trackable_id'   => $order->id,
            'payment_method' => 'qr',
        ]);
    }

    // Онлайн-заказ: остаток уже списан при оформлении (OrderController::store)
    private function completeOnline(Order $order, Payment $payment): void
    {
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
            // Онлайн-заказы исторически помечены способом 'mbank' (checkout,
            // профиль, отчёты) — сохраняем, чтобы не разъехались фильтры
            'payment_method' => 'mbank',
        ]);
    }

    // Касса со смешанной оплатой: QR-часть подтверждена — продажа проводится
    // (см. PosSaleService::complete)
    private function completePos(Order $order, Payment $payment): void
    {
        app(\App\Services\Pos\PosSaleService::class)->completeAfterQrPayment($order, $payment);
    }

    /**
     * Атомарное списание остатка по позициям заказа (UPDATE stock = stock - x),
     * минус по учёту — алерт.
     */
    public function writeOffStock(Order $order): void
    {
        $items = $order->items()->orderBy('product_id')->get();

        foreach ($items as $item) {
            $product = Product::whereKey($item->product_id)->lockForUpdate()->first();
            if (! $product) {
                continue;
            }

            $baseQty = ($item->is_package && $product->package_size)
                ? (float) $item->quantity * (float) $product->package_size
                : (float) $item->quantity;

            $product->decrement('stock_quantity', $baseQty);
            $product->increment('sales_count', $baseQty);
            $product->update(['in_stock' => $product->stock_quantity > 0]);

            if ((float) $product->stock_quantity < 0) {
                Alerts::raise(Alerts::NEGATIVE_STOCK, "Остаток «{$product->name}» ушёл в минус ({$product->stock_quantity}) после продажи #{$order->order_number}. Проверьте учёт.", [
                    'product_id'   => $product->id,
                    'order_id'     => $order->id,
                    'stock'        => (string) $product->stock_quantity,
                ]);
            }
        }
    }
}
