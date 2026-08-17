<?php

namespace App\Services;

use App\Contracts\PaymentProviderInterface;
use App\Exceptions\SelfServiceException;
use App\Models\FinancialTransaction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Бизнес-логика кассы самообслуживания. Laravel — источник истины для цены,
 * остатка и итоговой суммы; frontend присылает только product_id/quantity.
 *
 * Остаток списывается ТОЛЬКО после подтверждения оплаты (confirmPayment),
 * не при создании заказа — иначе брошенная (неоплаченная) сессия кассы
 * навсегда "морозит" товар. Из-за этого отменённый/просроченный заказ не
 * требует возврата остатка (см. cancelOrder/getPaymentStatus).
 */
class SelfServiceOrderService
{
    public function __construct(private PaymentProviderInterface $paymentProvider)
    {
    }

    public function createOrder(array $items, ?string $terminalId, ?string $clientUuid = null): Order
    {
        // Идемпотентность повторного нажатия "Оплатить": фронт присылает
        // один и тот же client_uuid на каждый повтор одного и того же
        // чек-аута — второй вызов просто возвращает уже созданный заказ
        // вместо дубликата.
        if ($clientUuid) {
            $existing = Order::where('uuid', $clientUuid)->where('channel', 'self_service')->first();
            if ($existing) {
                return $existing->load('items', 'payments');
            }
        }

        if (empty($items)) {
            throw new SelfServiceException('Корзина пуста.');
        }

        return DB::transaction(function () use ($items, $terminalId, $clientUuid) {
            $subtotal       = 0;
            $resolvedItems  = [];

            foreach ($items as $line) {
                $product = Product::find($line['product_id']);

                if (! $product || ! $product->is_active) {
                    throw new SelfServiceException('Один из товаров недоступен для покупки.');
                }

                $qty = (float) $line['quantity'];
                if ($qty <= 0) {
                    throw new SelfServiceException("Некорректное количество для товара «{$product->name}».");
                }

                if ($product->stock_quantity < $qty) {
                    throw new SelfServiceException("Товара «{$product->name}» сейчас нет в достаточном количестве.");
                }

                $price = (float) ($product->sale_price ?? $product->price);
                $subtotal += $price * $qty;

                $resolvedItems[] = ['product' => $product, 'quantity' => $qty, 'price' => $price];
            }

            $terminalPrefix = $terminalId ?: 'SS1';
            $orderData      = [
                'order_number'   => $this->nextOrderNumber($terminalPrefix),
                'user_id'        => null,
                'staff_id'       => null,
                'subtotal'       => $subtotal,
                'discount'       => 0,
                'total'          => $subtotal,
                'status'         => 'pending',
                'payment_status' => 'pending',
                'payment_method' => 'qr',
                'channel'        => 'self_service',
                'terminal_id'    => $terminalPrefix,
                'currency'       => 'SOM',
                'notes'          => "Касса самообслуживания ({$terminalPrefix})",
            ];
            if ($clientUuid) {
                $orderData['uuid'] = $clientUuid;
            }

            try {
                $order = Order::create($orderData);
            } catch (\Illuminate\Database\QueryException $e) {
                // Гонка двух одновременных запросов с одним client_uuid (двойной тап
                // по "Оплатить") — второй наткнётся на unique(uuid), просто отдаём
                // заказ, который успел создать первый.
                if ($clientUuid && (int) $e->getCode() === 23505) {
                    return Order::where('uuid', $clientUuid)->firstOrFail()->load('items', 'payments');
                }
                throw $e;
            }

            foreach ($resolvedItems as $line) {
                OrderItem::create([
                    'order_id'       => $order->id,
                    'product_id'     => $line['product']->id,
                    'product_name'   => $line['product']->name,
                    'product_sku'    => $line['product']->sku,
                    'purchase_price' => $line['product']->purchase_price,
                    'quantity'       => $line['quantity'],
                    'is_package'     => false,
                    'price'          => $line['price'],
                    'total'          => $line['price'] * $line['quantity'],
                ]);
            }

            $payment = Payment::create([
                'order_id'       => $order->id,
                'payment_method' => 'qr',
                'status'         => 'pending',
                'amount'         => $subtotal,
                'currency'       => 'SOM',
            ]);

            $paymentPayload = $this->paymentProvider->createPayment($order, $payment);

            $payment->update([
                'transaction_id'  => $paymentPayload['payment_id'],
                'payment_details' => json_encode($paymentPayload),
            ]);

            return $order->fresh(['items', 'payments']);
        });
    }

    public function getPaymentStatus(Order $order): array
    {
        if ($order->payment_status === 'paid') {
            return ['status' => 'paid'];
        }

        if ($order->status === 'cancelled') {
            return ['status' => 'cancelled'];
        }

        $payment = $order->payments()->latest('id')->first();
        if ($payment) {
            $details   = json_decode((string) $payment->payment_details, true) ?: [];
            $expiresAt = $details['expires_at'] ?? null;

            if ($expiresAt && now()->greaterThan(Carbon::parse($expiresAt))) {
                $this->expireOrder($order, $payment);
                return ['status' => 'expired'];
            }
        }

        return ['status' => 'pending'];
    }

    public function cancelOrder(Order $order): void
    {
        if ($order->payment_status === 'paid') {
            throw new SelfServiceException('Заказ уже оплачен, отменить нельзя — обратитесь на кассу.');
        }

        $order->update(['status' => 'cancelled', 'payment_status' => 'failed']);
        $order->payments()->where('status', 'pending')->update(['status' => 'failed']);
    }

    public function payInfo(string $token): array
    {
        $payload = $this->decodeToken($token);
        $order   = Order::where('id', $payload['order_id'])->where('channel', 'self_service')->first();

        if (! $order) {
            throw new SelfServiceException('Заказ не найден.');
        }

        if ($order->payment_status === 'paid') {
            return ['status' => 'paid', 'order_number' => $order->order_number, 'amount' => (float) $order->total];
        }

        if ($order->status === 'cancelled' || $payload['exp'] < now()->timestamp) {
            throw new SelfServiceException('Время оплаты истекло. Вернитесь к кассе и начните покупку заново.');
        }

        return [
            'status'       => 'pending',
            'order_number' => $order->order_number,
            'amount'       => (float) $order->total,
            'expires_at'   => date(DATE_ATOM, $payload['exp']),
        ];
    }

    public function confirmPayment(string $token): Order
    {
        $payload = $this->decodeToken($token);

        return DB::transaction(function () use ($payload) {
            $order = Order::where('id', $payload['order_id'])->lockForUpdate()->first();
            if (! $order || $order->channel !== 'self_service') {
                throw new SelfServiceException('Заказ не найден.');
            }

            // Идемпотентность: повторный тап "Я оплатил" на уже оплаченном
            // заказе просто возвращает текущее состояние, не задваивая продажу.
            if ($order->payment_status === 'paid') {
                return $order->fresh(['items']);
            }

            if ($order->status === 'cancelled' || $payload['exp'] < now()->timestamp) {
                $order->update(['status' => 'cancelled', 'payment_status' => 'failed']);
                throw new SelfServiceException('Время оплаты истекло. Начните покупку заново на кассе.');
            }

            $payment = Payment::where('order_id', $order->id)
                ->where('transaction_id', $payload['payment_id'])
                ->lockForUpdate()
                ->first();
            if (! $payment) {
                throw new SelfServiceException('Платёж не найден.');
            }

            $order->load('items');

            // Финальная проверка остатка/активности перед списанием — цена и
            // наличие уже проверялись при создании заказа, но за время, пока
            // покупатель шёл от кассы к QR, могло измениться.
            foreach ($order->items as $item) {
                $product = Product::find($item->product_id);
                if (! $product || ! $product->is_active || $product->stock_quantity < $item->quantity) {
                    $order->update(['status' => 'cancelled', 'payment_status' => 'failed']);
                    $payment->update(['status' => 'failed']);
                    throw new SelfServiceException(
                        "Товар «{$item->product_name}» закончился, пока шла оплата. Заказ отменён, обратитесь на кассу."
                    );
                }
            }

            foreach ($order->items as $item) {
                $product = Product::find($item->product_id);
                $product->decrement('stock_quantity', $item->quantity);
                $product->increment('sales_count', $item->quantity);
                $product->update(['in_stock' => $product->stock_quantity > 0]);
            }

            $order->update(['status' => 'delivered', 'payment_status' => 'paid']);
            $payment->update(['status' => 'completed', 'paid_at' => now()]);

            FinancialTransaction::create([
                'user_id'        => null,
                'type'           => 'income',
                'amount'         => $order->total,
                'category'       => 'Продажа товаров (Self-Service - QR)',
                'description'    => "Оплата заказа Self-Service #{$order->order_number}",
                'trackable_type' => Order::class,
                'trackable_id'   => $order->id,
                'payment_method' => 'qr',
            ]);

            return $order->fresh(['items']);
        });
    }

    private function expireOrder(Order $order, Payment $payment): void
    {
        DB::transaction(function () use ($order, $payment) {
            $order->refresh();
            if ($order->payment_status === 'paid') {
                return;
            }
            $order->update(['status' => 'cancelled', 'payment_status' => 'failed']);
            $payment->update(['status' => 'failed']);
        });
    }

    private function decodeToken(string $token): array
    {
        try {
            $payload = json_decode(Crypt::decryptString($token), true);
        } catch (DecryptException $e) {
            throw new SelfServiceException('Ссылка на оплату недействительна.');
        }

        if (! is_array($payload) || empty($payload['order_id']) || empty($payload['payment_id']) || empty($payload['exp'])) {
            throw new SelfServiceException('Ссылка на оплату повреждена.');
        }

        return $payload;
    }

    // Тот же принцип нумерации, что и в PosController::store — заказы разных
    // терминалов (касса кассира "k1", self-service "SS1") не пересекаются по
    // номеру и остаются сортируемыми по возрастанию внутри терминала.
    private function nextOrderNumber(string $terminalPrefix): string
    {
        $last = Order::where('order_number', 'like', "{$terminalPrefix}-%")
            ->orderByRaw("CAST(SUBSTRING(order_number FROM '[0-9]+$') AS INTEGER) DESC")
            ->first();

        $next = 1;
        if ($last) {
            preg_match('/-(\d+)$/', $last->order_number, $m);
            $next = isset($m[1]) ? (int) $m[1] + 1 : 1;
        }

        return "{$terminalPrefix}-{$next}";
    }
}
