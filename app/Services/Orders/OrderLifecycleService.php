<?php

namespace App\Services\Orders;

use App\Exceptions\OrderStateException;
use App\Models\Coupon;
use App\Models\CustomerDebt;
use App\Models\DebtPayment;
use App\Models\FinancialTransaction;
use App\Models\Order;
use App\Services\Payments\OrderQrPaymentService;
use Illuminate\Support\Facades\DB;

/**
 * Отмена заказа — одна реализация для всех путей: кнопка отмены (покупатель
 * или персонал), смена статуса в админке, автоотмена неоплаченных заказов.
 * Раньше отмена через смену статуса не создавала возврат денег, а через
 * кнопку — создавала, и отчёты зависели от того, какой кнопкой нажали.
 */
class OrderLifecycleService
{
    public const ACTIVE_STATUSES = ['pending', 'processing', 'shipped', 'delivered'];

    public function __construct(private OrderQrPaymentService $qrPayments)
    {
    }

    /**
     * Отменить заказ: сначала гасим неоплаченные QR у провайдера (по ним
     * больше нельзя заплатить), затем в транзакции возвращаем остаток, купон,
     * деньги (если заказ был оплачен — возврат наличными) и закрываем долг.
     *
     * @param  string[]  $allowedFrom  статусы, из которых разрешена отмена
     *
     * @throws OrderStateException заказ нельзя отменить (статус, оплата прошла)
     * @throws \App\Exceptions\PaymentProviderUnavailableException
     */
    public function cancel(Order $order, ?int $actorId, array $allowedFrom = self::ACTIVE_STATUSES, bool $allowPaid = true): Order
    {
        if (! $this->qrPayments->cancelPendingPayments($order)) {
            throw new OrderStateException('Заказ только что оплачен по QR. Обновите страницу и проверьте его перед отменой.');
        }

        return DB::transaction(function () use ($order, $actorId, $allowedFrom, $allowPaid) {
            // Блокировка + повторная проверка внутри транзакции: параллельная
            // отмена или пришедшая оплата видны здесь, а не до блокировки
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! in_array($order->status, $allowedFrom, true)) {
                throw new OrderStateException('Заказ в статусе «' . $order->status . '» отменить нельзя.');
            }

            $wasPaid = $order->payment_status === 'paid';
            if ($wasPaid && ! $allowPaid) {
                throw new OrderStateException('Этот заказ уже оплачен — для отмены свяжитесь с магазином.');
            }

            $this->returnStock($order);

            // Возврат — не только за оплаченный заказ: по продаже в долг
            // (оплата «ожидает») покупатель мог внести первый взнос и выплаты
            $refunded = $this->recordRefund($order, $actorId, "Возврат средств за отменённый заказ #{$order->order_number}");

            $order->update([
                'status'         => 'cancelled',
                'payment_status' => $wasPaid || $refunded > 0 ? 'refunded' : $order->payment_status,
            ]);

            $this->releaseCoupon($order);

            $this->cancelDebt($order);

            return $order->fresh();
        });
    }

    // Возврат на склад того, что ещё не возвращено (частичные возвраты уже
    // вернули своё через returnItems)
    public function returnStock(Order $order): void
    {
        foreach ($order->items()->with('product')->get() as $item) {
            $product     = $item->product;
            $qtyToReturn = max(0, (float) $item->quantity - (float) $item->refunded_quantity);

            if ($product && $qtyToReturn > 0) {
                $baseQty = ($item->is_package && $product->package_size)
                    ? $qtyToReturn * (float) $product->package_size
                    : $qtyToReturn;
                $product->increment('stock_quantity', $baseQty);
                $product->update(['in_stock' => $product->stock_quantity > 0]);
            }

            $item->update(['refunded_quantity' => $item->quantity]);
        }
    }

    /**
     * Сколько денег покупатель отдал за заказ и ещё не получил обратно:
     * приход по самому заказу (оплата, первый взнос в долг) плюс выплаты по
     * долгу этого заказа (они записаны на DebtPayment, а не на заказ), минус
     * уже сделанные возвраты. Без выплат по долгу покупателю, погасившему
     * долг, при возврате отдавали только первый взнос.
     */
    public function receivedAmount(Order $order): float
    {
        $orderIncome = (float) FinancialTransaction::where('trackable_type', Order::class)
            ->where('trackable_id', $order->id)
            ->where('type', 'income')
            ->sum('amount');

        $debtIncome = (float) FinancialTransaction::where('trackable_type', DebtPayment::class)
            ->whereIn('trackable_id', DebtPayment::whereIn(
                'customer_debt_id',
                CustomerDebt::where('order_id', $order->id)->select('id'),
            )->select('id'))
            ->where('type', 'income')
            ->sum('amount');

        $refunded = (float) FinancialTransaction::where('trackable_type', Order::class)
            ->where('trackable_id', $order->id)
            ->where('category', 'refund')
            ->sum('amount');

        return round($orderIncome + $debtIncome - $refunded, 2);
    }

    // Возврат денег покупателю — только наличными из кассы (решение
    // магазина): расход на всё полученное по заказу и ещё не возвращённое
    public function recordRefund(Order $order, ?int $actorId, string $description): float
    {
        $amount = $this->receivedAmount($order);
        if ($amount <= 0) {
            return 0.0;
        }

        FinancialTransaction::create([
            'user_id'        => $actorId,
            'type'           => 'expense',
            'amount'         => $amount,
            'category'       => 'refund',
            'trackable_type' => Order::class,
            'trackable_id'   => $order->id,
            'description'    => $description,
            'payment_method' => 'cash',
        ]);

        return $amount;
    }

    // Заказ отменён или возвращён целиком — долг по нему закрывается. Деньги,
    // внесённые по долгу, recordRefund уже вернул, поэтому и погашенный долг
    // отменяется, а не остаётся «оплаченным»
    public function cancelDebt(Order $order): void
    {
        CustomerDebt::where('order_id', $order->id)
            ->where('status', '!=', 'cancelled')
            ->update(['status' => 'cancelled', 'remaining_amount' => 0]);
    }

    public function releaseCoupon(Order $order): void
    {
        if ($order->coupon_id) {
            Coupon::whereKey($order->coupon_id)->where('used_count', '>', 0)->decrement('used_count');
        }
    }
}
