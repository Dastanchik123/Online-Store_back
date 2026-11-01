<?php

namespace App\Services\Pos;

use App\Contracts\PaymentProviderInterface;
use App\Exceptions\OrderStateException;
use App\Exceptions\PosSaleException;
use App\Models\Coupon;
use App\Models\CustomerDebt;
use App\Models\FinancialTransaction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Services\Orders\OrderNumberService;
use App\Services\Payments\OrderQrPaymentService;
use App\Services\Payments\PaymentSettlementService;
use App\Services\WeightedBarcodeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Продажа на кассе. Сервер — источник истины: цены, вес, скидка, итог и
 * права кассира проверяются здесь, а не на экране кассы.
 *
 * Оплата: наличные и/или QR GoPay (смешанная оплата). Без QR продажа
 * проводится сразу. С QR — заказ ждёт подтверждения банка (status pending),
 * и только после него списывается остаток и записываются деньги
 * (PaymentSettlementService → completeAfterQrPayment).
 *
 * В долг: первый взнос — наличные и/или QR, остаток — долг покупателя. С QR
 * долг создаётся только после подтверждения банка.
 *
 * Права кассира (страница «Права доступа»):
 *  pos.change_price        — продавать не по цене каталога
 *  pos.discount / pos.discount_unlimited — ручная скидка (лимит в настройках)
 *  pos.sell_negative_stock — продавать больше, чем есть по учёту
 *  pos.manual_weight       — весовой товар без штрихкода весов (вес вручную)
 */
class PosSaleService
{
    public const CATEGORY_CASH = 'Продажа товаров (POS - Наличными)';
    public const CATEGORY_QR   = 'Продажа товаров (POS - QR)';

    private const DEFAULT_MAX_DISCOUNT_PERCENT = 10;
    private const WEIGHT_TOLERANCE_KG = 0.0005;
    // Суммы в orders/order_items — decimal(10,2): больше в БД не влезет
    private const MAX_AMOUNT = 99999999.99;

    public function __construct(
        private WeightedBarcodeService $barcodes,
        private OrderNumberService $numbers,
        private OrderQrPaymentService $qrPayments,
        private PaymentProviderInterface $paymentProvider,
    ) {
    }

    /**
     * @return array{order: Order, payment: ?Payment}
     */
    public function create(array $data, User $cashier): array
    {
        $cash = round((float) ($data['cash_amount'] ?? 0), 2);
        $qr   = round((float) ($data['qr_amount'] ?? 0), 2);

        $order = DB::transaction(function () use ($data, $cashier, $cash, $qr) {
            $lines    = $this->resolveLines($data['items'], $cashier);
            $subtotal = round(array_sum(array_map(fn ($l) => $l['price'] * $l['quantity'], $lines)), 2);
            // Обычно это ошибка ввода: штрихкод отсканирован в поле
            // количества или цены. Без проверки вставка падала с 500.
            if ($subtotal > self::MAX_AMOUNT) {
                throw new PosSaleException('Сумма чека слишком большая — проверьте количество и цены товаров.');
            }

            [$couponDiscount, $manualDiscount, $coupon] = $this->resolveDiscounts($data, $subtotal, $cashier);
            $discount = $couponDiscount + $manualDiscount;
            $total    = round($subtotal - $discount, 2);

            $isDebt = (bool) ($data['is_debt'] ?? false);
            $userId = $data['user_id'] ?? null;
            $this->checkPayment($total, $cash, $qr, $isDebt, $userId);
            $this->checkStock($lines, $cashier);

            $awaitingQr = $qr > 0;
            $cashPart   = min($cash, max(0, $total - $qr));

            $order = Order::create([
                'order_number'      => $this->numbers->next(Order::CHANNEL_POS),
                'channel'           => Order::CHANNEL_POS,
                'user_id'           => $userId,
                'staff_id'          => $cashier->id,
                'subtotal'          => $subtotal,
                'discount'          => $discount,
                'manual_discount'   => $manualDiscount,
                'coupon_id'         => $coupon?->id,
                'total'             => $total,
                'status'            => $awaitingQr ? 'pending' : 'delivered',
                'payment_status'    => $awaitingQr || $isDebt ? 'pending' : 'paid',
                'payment_method'    => $awaitingQr ? ($cashPart > 0 ? 'mixed' : 'qr') : 'cash',
                'currency'          => 'SOM',
                'notes'             => 'Продажа на кассе',
                'is_financed'       => true,
                'cash_received'     => $cashPart,
                // QR-часть смешанной оплаты (колонка исторически называлась
                // «перевод» — ручной перевод на кассе больше не принимается)
                'transfer_received' => $qr,
                // Внесено наличными сверх нужного — для печати чека
                'change_amount'     => round(max(0, $cash - $cashPart), 2),
                // С QR долг создаётся только после подтверждения банка —
                // до этого признак и срок хранятся в заказе
                'is_debt'           => $isDebt,
                'debt_due_date'     => $isDebt ? ($data['due_date'] ?? null) : null,
            ]);

            foreach ($lines as $line) {
                OrderItem::create([
                    'order_id'       => $order->id,
                    'product_id'     => $line['product']->id,
                    'product_name'   => $line['product']->name,
                    'product_sku'    => $line['product']->sku,
                    'purchase_price' => $line['product']->purchase_price,
                    'quantity'       => $line['quantity'],
                    'unit'           => $line['product']->unit ?: 'шт',
                    'is_package'     => $line['is_package'],
                    'price'          => $line['price'],
                    'total'          => round($line['price'] * $line['quantity'], 2),
                ]);
            }

            // Использование купона — как в онлайн-заказе; при отмене вернётся
            $coupon?->increment('used_count');

            if (! $awaitingQr) {
                $this->writeOffAndRecord($order, $cashier->id);

                if ($isDebt) {
                    $this->createDebt($order, $cashPart);
                }
            }

            return $order;
        });

        if ($qr <= 0) {
            return ['order' => $order->load(['items.product', 'user']), 'payment' => null];
        }

        // QR — вне транзакции (внешний HTTP-вызов). Не получилось — продажа
        // отменяется целиком: кассир примет оплату наличными.
        try {
            $payment = $this->qrPayments->createQrPayment($order, $qr, 'qr');
        } catch (\Throwable $e) {
            Log::error('POS: не удалось создать QR: ' . $e->getMessage(), ['order_id' => $order->id]);
            $this->markCancelled($order);
            throw new PosSaleException('QR-оплата сейчас недоступна. Примите оплату наличными.', 'qr_amount', 503);
        }

        return ['order' => $order->load(['items.product', 'user']), 'payment' => $payment];
    }

    /**
     * Банк подтвердил QR-часть — продажа проводится: списание остатка, приход
     * наличных и QR. Вызывается из PaymentSettlementService внутри транзакции
     * с заблокированным заказом.
     */
    public function completeAfterQrPayment(Order $order, Payment $payment): void
    {
        $this->writeOffAndRecord($order, $order->staff_id, $payment);

        // В долг с первым взносом по QR: товар у покупателя, остаток — долг
        if ($order->is_debt) {
            $this->createDebt($order, (float) $order->cash_received + (float) $payment->amount);
            $order->update(['status' => 'delivered']);

            return;
        }

        $order->update(['status' => 'delivered', 'payment_status' => 'paid']);
    }

    /**
     * Отменить продажу, ожидающую QR (покупатель передумал, QR истёк).
     * Остаток не трогаем — он списывается только после оплаты.
     *
     * @throws OrderStateException оплата уже прошла
     * @throws \App\Exceptions\PaymentProviderUnavailableException
     */
    public function cancelPending(Order $order): Order
    {
        if (! $this->qrPayments->cancelPendingPayments($order)) {
            throw new OrderStateException('Оплата по QR уже прошла — продажа проведена. Распечатайте чек.');
        }

        return $this->markCancelled($order);
    }

    /**
     * Новый QR взамен истёкшего — старый гасится у провайдера.
     *
     * @throws OrderStateException
     * @throws \App\Exceptions\PaymentProviderUnavailableException
     */
    public function regenerateQr(Order $order): Payment
    {
        if ($order->status !== 'pending' || $order->payment_status !== 'pending') {
            throw new OrderStateException('Продажа уже не ожидает оплаты.');
        }

        if (! $this->qrPayments->cancelPendingPayments($order)) {
            throw new OrderStateException('Оплата по QR уже прошла — продажа проведена. Распечатайте чек.');
        }

        return $this->qrPayments->createQrPayment($order, (float) $order->transfer_received, 'qr');
    }

    /**
     * Текущее состояние продажи для экрана ожидания QR. С $askProvider —
     * дополнительно спросить провайдера (если вебхук задерживается).
     */
    public function status(Order $order, bool $askProvider = false): Order
    {
        if ($askProvider && $order->status === 'pending') {
            $payment = $order->payments()->whereIn('status', ['pending', 'processing'])->latest('id')->first();

            if ($payment && $this->paymentProvider->fetchStatus($payment) === PaymentProviderInterface::STATUS_PAID) {
                app(PaymentSettlementService::class)->settleCommitted($payment->transaction_id);
            }
        }

        return $order->fresh(['items.product', 'user', 'payments']);
    }

    // ---------------------------------------------------------------

    private function markCancelled(Order $order): Order
    {
        return DB::transaction(function () use ($order) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($order->payment_status === 'paid') {
                throw new OrderStateException('Оплата по QR уже прошла — продажа проведена. Распечатайте чек.');
            }

            $order->update(['status' => 'cancelled', 'payment_status' => 'failed']);
            $order->payments()->whereIn('status', ['pending', 'processing'])->update(['status' => 'failed']);

            if ($order->coupon_id) {
                Coupon::whereKey($order->coupon_id)->where('used_count', '>', 0)->decrement('used_count');
            }

            return $order->fresh();
        });
    }

    // Списание остатка (минус — алерт: товар уже у покупателя) и приход денег
    private function writeOffAndRecord(Order $order, ?int $staffId, ?Payment $qrPayment = null): void
    {
        app(PaymentSettlementService::class)->writeOffStock($order);

        $cash = (float) $order->cash_received;
        if ($cash > 0) {
            FinancialTransaction::create([
                'user_id'        => $staffId,
                'type'           => 'income',
                'amount'         => $cash,
                'category'       => self::CATEGORY_CASH,
                'description'    => "Оплата заказа POS #{$order->order_number} (Наличные)",
                'trackable_type' => Order::class,
                'trackable_id'   => $order->id,
                'payment_method' => 'cash',
            ]);
        }

        if ($qrPayment) {
            FinancialTransaction::create([
                'user_id'        => $staffId,
                'type'           => 'income',
                'amount'         => $qrPayment->amount,
                'category'       => self::CATEGORY_QR,
                'description'    => "Оплата заказа POS #{$order->order_number} (QR)",
                'trackable_type' => Order::class,
                'trackable_id'   => $order->id,
                'payment_method' => 'qr',
            ]);
        }
    }

    // $paid — первый взнос (наличные и/или подтверждённый QR)
    private function createDebt(Order $order, float $paid): void
    {
        $paid      = round($paid, 2);
        $remaining = round((float) $order->total - $paid, 2);
        if ($remaining <= 0 || ! $order->user_id) {
            return;
        }

        CustomerDebt::create([
            'user_id'          => $order->user_id,
            'order_id'         => $order->id,
            'total_amount'     => $order->total,
            'paid_amount'      => $paid,
            'remaining_amount' => $remaining,
            'due_date'         => $order->debt_due_date,
            'status'           => $paid > 0 ? 'partial' : 'active',
        ]);
    }

    /**
     * Позиции чека: цена, количество и вес проверяются по каталогу и правам.
     */
    private function resolveLines(array $items, User $cashier): array
    {
        $lines = [];

        foreach ($items as $item) {
            $product = Product::find($item['product_id']);
            if (! $product) {
                throw new PosSaleException('Товар не найден.');
            }

            $isPackage = (bool) ($item['is_package'] ?? false) && $product->package_size;
            $quantity  = (float) $item['quantity'];
            $price     = (float) $item['price'];

            if ($product->is_weighted) {
                [$quantity, $price] = $this->resolveWeighted($product, $item, $cashier);
            } else {
                if (! $isPackage && $product->requiresWholeQuantity() && floor($quantity) != $quantity) {
                    throw new PosSaleException("Товар «{$product->name}» продаётся только целыми штуками.");
                }
                $this->checkPrice($product, $price, (bool) $isPackage, $cashier);
            }

            // Цена 0 (не заполнена в каталоге) — проверка себестоимости её не
            // ловит, если и себестоимость 0: товар уходил бесплатно. И для
            // весового, и для штучного товара
            if ($price <= 0) {
                throw new PosSaleException("У товара «{$product->name}» не указана цена. Укажите цену в карточке товара.");
            }

            $lines[] = ['product' => $product, 'quantity' => $quantity, 'price' => $price, 'is_package' => (bool) $isPackage];
        }

        return $lines;
    }

    // Весовой товар: со штрихкодом весов — вес и цена только из штрихкода и
    // каталога; без штрихкода — вес вводит кассир (право pos.manual_weight)
    private function resolveWeighted(Product $product, array $item, User $cashier): array
    {
        $catalogPrice = (float) ($product->sale_price ?? $product->price);
        $quantity     = (float) $item['quantity'];

        if (! empty($item['barcode'])) {
            $parsed = $this->barcodes->parse($item['barcode']);
            if ($parsed === null) {
                throw new PosSaleException("Некорректный весовой штрихкод для товара «{$product->name}».");
            }
            if ($parsed['product_id'] !== $product->id) {
                throw new PosSaleException("Штрихкод не соответствует товару «{$product->name}».");
            }
            if (abs($parsed['weight_kg'] - $quantity) > self::WEIGHT_TOLERANCE_KG) {
                throw new PosSaleException("Вес «{$product->name}» в чеке не совпадает со штрихкодом весов ({$parsed['weight_kg']} {$product->unit}).");
            }
            $quantity = $parsed['weight_kg'];
        } elseif (! $cashier->hasPermission('pos.manual_weight')) {
            throw new PosSaleException("Для «{$product->name}» нужен штрихкод с весов: вводить вес вручную вам не разрешено.");
        }

        if ($product->min_weight !== null && $quantity < (float) $product->min_weight) {
            throw new PosSaleException("Вес товара «{$product->name}» меньше допустимого минимума ({$product->min_weight} {$product->unit}).");
        }
        if ($product->max_weight !== null && $quantity > (float) $product->max_weight) {
            throw new PosSaleException("Вес товара «{$product->name}» превышает допустимый максимум ({$product->max_weight} {$product->unit}).");
        }

        return [$quantity, $catalogPrice];
    }

    private function checkPrice(Product $product, float $price, bool $isPackage, User $cashier): void
    {
        if ($isPackage && $product->package_price !== null) {
            $catalogPrice = (float) $product->package_price;
            $costPrice    = (float) ($product->purchase_price ?? 0) * (float) ($product->package_size ?? 1);
        } else {
            $catalogPrice = (float) ($product->sale_price ?? $product->price);
            $costPrice    = (float) ($product->purchase_price ?? 0);
        }

        if (abs($price - $catalogPrice) > 0.01 && ! $cashier->hasPermission('pos.change_price')) {
            throw new PosSaleException("Изменение цены вам не разрешено: «{$product->name}» продаётся по {$catalogPrice} сом.");
        }

        if ($price < $costPrice) {
            throw new PosSaleException("Цена товара «{$product->name}» ({$price} сом) ниже себестоимости ({$costPrice} сом). Продажа невозможна.");
        }
    }

    // Продажа больше, чем есть по учёту — только с правом: товар физически
    // на кассе, но учёт расходится (не оприходовали, пересорт)
    private function checkStock(array $lines, User $cashier): void
    {
        if ($cashier->hasPermission('pos.sell_negative_stock')) {
            return;
        }

        $needed = [];
        foreach ($lines as $line) {
            $product = $line['product'];
            $base    = $line['is_package'] ? $line['quantity'] * (float) $product->package_size : $line['quantity'];
            $needed[$product->id] = ($needed[$product->id] ?? 0) + $base;
        }

        foreach ($needed as $productId => $quantity) {
            $product = Product::whereKey($productId)->lockForUpdate()->first();
            if ((float) $product->stock_quantity + 0.0005 < $quantity) {
                throw new PosSaleException("Недостаточно товара «{$product->name}»: по учёту в наличии {$product->stock_quantity} {$product->unit}.");
            }
        }
    }

    private function checkPayment(float $total, float $cash, float $qr, bool $isDebt, $userId): void
    {
        if ($qr < 0 || $cash < 0) {
            throw new PosSaleException('Сумма оплаты не может быть отрицательной.', 'cash_amount');
        }
        if ($qr > $total + 0.001) {
            throw new PosSaleException('По QR нельзя оплатить больше суммы чека — сдачу с QR не дают.', 'qr_amount');
        }

        if ($isDebt) {
            if (! $userId) {
                throw new PosSaleException('Для продажи в долг выберите покупателя.', 'user_id');
            }
            // Как на кассе: кнопка «В долг» видна только при явно включённой
            // настройке, поэтому и сервер без неё долг не разрешает
            $allowDebt = Setting::where('key', 'pos_allow_debt')->value('value');
            if (! in_array($allowDebt, ['1', 'true'], true)) {
                throw new PosSaleException('Продажа в долг запрещена настройками системы.', 'is_debt');
            }
            // Иначе продажа молча проходила как оплаченная и долг не создавался
            // Первый взнос — наличные и/или QR
            if (round(($cash + $qr) * 100) >= round($total * 100)) {
                throw new PosSaleException('Внесённая сумма покрывает весь чек — долга нет. Снимите «В долг» или уменьшите первый взнос.', 'cash_amount');
            }

            return;
        }

        // Сравнение в тыйынах: прежний допуск «+ 0.01» пропускал недоплату
        // ровно на тыйын (249.99 за чек на 250 проходил как оплаченный)
        if (round(($cash + $qr) * 100) < round($total * 100)) {
            $short = round($total - $cash - $qr, 2);
            throw new PosSaleException("Недостаточно оплаты: не хватает {$short} сом.", 'cash_amount');
        }
    }

    // Скидка в чеке = купон + ручная скидка кассира. Купон сервер считает
    // сам по коду, ручную скидку даёт только роль с правом (в пределах лимита)
    private function resolveDiscounts(array $data, float $subtotal, User $cashier): array
    {
        $coupon         = null;
        $couponDiscount = 0.0;

        if (! empty($data['coupon_code'])) {
            $coupon = Coupon::where('code', $data['coupon_code'])->lockForUpdate()->first();

            if (! $coupon || ! $coupon->isValid()) {
                throw new PosSaleException('Купон недействителен или истёк.', 'coupon_code');
            }
            if ($subtotal < (float) $coupon->min_order_amount) {
                throw new PosSaleException("Минимальная сумма чека для этого купона: {$coupon->min_order_amount} сом.", 'coupon_code');
            }

            $couponDiscount = $coupon->type === 'percent'
                ? $subtotal * (float) $coupon->value / 100
                : (float) $coupon->value;
            $couponDiscount = round(min($couponDiscount, $subtotal), 2);
        }

        $manual = round((float) ($data['manual_discount'] ?? 0), 2);
        if ($manual > 0) {
            $unlimited = $cashier->hasPermission('pos.discount_unlimited');

            if (! $unlimited && ! $cashier->hasPermission('pos.discount')) {
                throw new PosSaleException('У вас нет права давать скидку.', 'manual_discount');
            }

            if (! $unlimited) {
                $raw        = Setting::where('key', 'pos_max_discount_percent')->value('value');
                $maxPercent = is_numeric($raw) ? (float) $raw : self::DEFAULT_MAX_DISCOUNT_PERCENT;
                $maxAmount  = round($subtotal * $maxPercent / 100, 2);

                if ($manual > $maxAmount + 0.001) {
                    throw new PosSaleException("Скидка больше допустимой: максимум {$maxPercent}% ({$maxAmount} сом).", 'manual_discount');
                }
            }
        }

        if ($couponDiscount + $manual > $subtotal + 0.001) {
            throw new PosSaleException('Скидка не может быть больше суммы чека.', 'manual_discount');
        }

        return [$couponDiscount, $manual, $coupon];
    }
}
