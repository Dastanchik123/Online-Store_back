<?php

namespace App\Services;

use App\Exceptions\PaymentProviderUnavailableException;
use App\Exceptions\SelfServiceException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Services\Payments\OrderQrPaymentService;
use App\Services\Payments\PaymentSettlementService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Бизнес-логика кассы самообслуживания. Laravel — источник истины для цены,
 * остатка и итоговой суммы; frontend присылает только product_id/quantity.
 *
 * Остаток списывается ТОЛЬКО после подтверждения оплаты банком
 * (PaymentSettlementService) — иначе брошенная (неоплаченная) сессия кассы
 * навсегда «морозит» товар. Поэтому отменённый/просроченный заказ не требует
 * возврата остатка.
 */
class SelfServiceOrderService
{
    public function __construct(
        private OrderQrPaymentService $qrPayments,
        private PaymentSettlementService $settlement,
    ) {
    }

    public function createOrder(array $items, ?string $terminalId, ?string $clientUuid = null): Order
    {
        // Идемпотентность повторного нажатия "Оплатить": фронт присылает
        // один и тот же client_uuid на каждый повтор одного и того же
        // чек-аута — второй вызов просто возвращает уже созданный заказ
        // вместо дубликата.
        if ($clientUuid) {
            $existing = Order::where('uuid', $clientUuid)->where('channel', Order::CHANNEL_SELF_SERVICE)->first();
            if ($existing) {
                return $existing->load('items', 'payments');
            }
        }

        if (empty($items)) {
            throw new SelfServiceException('Корзина пуста.');
        }

        $order = DB::transaction(function () use ($items, $terminalId, $clientUuid) {
            $subtotal      = 0;
            $resolvedItems = [];

            foreach ($items as $line) {
                $product = Product::find($line['product_id']);

                if (! $product || ! $product->is_active) {
                    throw new SelfServiceException('Один из товаров недоступен для покупки.');
                }

                $qty = (float) $line['quantity'];
                // Штучный товар — только целыми, как на кассе (PosSaleService):
                // раньше киоск принимал 1.5 шт и выставлял счёт за полштуки
                if ($qty <= 0 || (! $product->is_weighted && $product->requiresWholeQuantity() && floor($qty) != $qty)) {
                    throw new SelfServiceException("Некорректное количество для товара «{$product->name}».");
                }

                // Проверка до оплаты — чтобы не брать деньги за то, чего по
                // учёту нет. После оплаты нехватка уже не отменяет продажу.
                if ($product->stock_quantity < $qty) {
                    throw new SelfServiceException("Товара «{$product->name}» сейчас нет в достаточном количестве.");
                }

                $price     = (float) ($product->sale_price ?? $product->price);
                // Цена не заполнена (0) — иначе товар уходил бесплатно
                if ($price <= 0) {
                    throw new SelfServiceException("У товара «{$product->name}» не указана цена — обратитесь к сотруднику.");
                }
                $subtotal += $price * $qty;

                $resolvedItems[] = ['product' => $product, 'quantity' => $qty, 'price' => $price];
            }

            $terminalPrefix = $terminalId ?: 'SS1';
            $orderData      = [
                'order_number'   => app(\App\Services\Orders\OrderNumberService::class)->next(Order::CHANNEL_SELF_SERVICE),
                'user_id'        => null,
                'staff_id'       => null,
                'subtotal'       => $subtotal,
                'discount'       => 0,
                'total'          => $subtotal,
                'status'         => 'pending',
                'payment_status' => 'pending',
                'payment_method' => 'qr',
                'channel'        => Order::CHANNEL_SELF_SERVICE,
                'terminal_id'    => $terminalPrefix,
                'currency'       => 'SOM',
                'notes'          => "Касса самообслуживания ({$terminalPrefix})",
            ];
            if ($clientUuid) {
                $orderData['uuid'] = $clientUuid;
            }

            try {
                // Вложенная транзакция = SAVEPOINT: в Postgres ошибка внутри
                // транзакции «ломает» её целиком, и без точки сохранения
                // следующий запрос падал бы с "current transaction is aborted"
                $order = DB::transaction(fn () => Order::create($orderData));
            } catch (QueryException $e) {
                // Гонка двух одновременных запросов с одним client_uuid (двойной
                // тап по "Оплатить") — второй наткнётся на unique(uuid)
                if ($clientUuid && (string) $e->getCode() === '23505') {
                    return Order::where('uuid', $clientUuid)->firstOrFail();
                }
                throw $e;
            }

            if (! $order->wasRecentlyCreated) {
                return $order;
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

            return $order;
        });

        if (! $order->wasRecentlyCreated) {
            return $order->load('items', 'payments');
        }

        // QR — вне транзакции (внешний HTTP-вызов). Если провайдер не ответил,
        // заказ удаляем целиком: оплаты по нему не было, а повтор с тем же
        // client_uuid должен создать его заново, а не вернуть «мёртвый».
        try {
            $this->qrPayments->createQrPayment($order, null, 'qr');
        } catch (\Throwable $e) {
            Log::error('Self-service: не удалось создать QR: ' . $e->getMessage(), ['order_id' => $order->id]);
            // forceDelete: Order «мягко» удаляемый, а мягко удалённая строка
            // держала бы unique(uuid) и повтор с тем же client_uuid падал бы
            DB::transaction(function () use ($order) {
                $order->payments()->delete();
                $order->items()->delete();
                $order->forceDelete();
            });
            throw new SelfServiceException('Оплата сейчас недоступна. Попробуйте ещё раз через минуту.');
        }

        return $order->fresh(['items', 'payments']);
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
                return ['status' => $this->expireOrder($order)];
            }
        }

        return ['status' => 'pending'];
    }

    public function cancelOrder(Order $order): void
    {
        try {
            // Сначала гасим QR у провайдера — по нему больше нельзя заплатить
            if (! $this->qrPayments->cancelPendingPayments($order)) {
                throw new SelfServiceException('Оплата уже прошла — заказ не отменён. Заберите чек.');
            }
        } catch (PaymentProviderUnavailableException $e) {
            throw new SelfServiceException($e->getMessage());
        }

        DB::transaction(function () use ($order) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            // Оплата могла прийти между проверкой и блокировкой
            if ($order->payment_status === 'paid') {
                throw new SelfServiceException('Заказ уже оплачен, отменить нельзя — обратитесь на кассу.');
            }

            $order->update(['status' => 'cancelled', 'payment_status' => 'failed']);
            $order->payments()->whereIn('status', ['pending', 'processing'])->update(['status' => 'failed']);
        });
    }

    public function payInfo(string $token): array
    {
        $payload = $this->decodeToken($token);
        $order   = Order::where('id', $payload['order_id'])->where('channel', Order::CHANNEL_SELF_SERVICE)->first();

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

    /**
     * Демо-подтверждение оплаты временным QR (только local/testing, см.
     * routes/api.php) — проводится тем же путём, что и настоящий вебхук.
     */
    public function confirmPayment(string $token): Order
    {
        $payload = $this->decodeToken($token);
        $order   = Order::find($payload['order_id']);

        if (! $order || $order->channel !== Order::CHANNEL_SELF_SERVICE) {
            throw new SelfServiceException('Заказ не найден.');
        }

        if ($order->payment_status !== 'paid' && ($order->status === 'cancelled' || $payload['exp'] < now()->timestamp)) {
            throw new SelfServiceException('Время оплаты истекло. Начните покупку заново на кассе.');
        }

        $this->settlement->settleCommitted($payload['payment_id']);

        return $order->fresh(['items']);
    }

    // QR истёк по времени: гасим его у провайдера (иначе банк ещё мог бы
    // принять оплату по уже «мёртвому» для кассы заказу) и отменяем заказ.
    // Возвращает итоговый статус для экрана кассы.
    private function expireOrder(Order $order): string
    {
        try {
            if (! $this->qrPayments->cancelPendingPayments($order)) {
                return 'paid';
            }
        } catch (PaymentProviderUnavailableException $e) {
            // Провайдер не ответил — статус неизвестен, касса спросит ещё раз
            return 'pending';
        }

        return DB::transaction(function () use ($order) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($order->payment_status === 'paid') {
                return 'paid';
            }
            $order->update(['status' => 'cancelled', 'payment_status' => 'failed']);

            return 'expired';
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
}
