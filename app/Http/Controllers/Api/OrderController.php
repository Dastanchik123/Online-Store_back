<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Requests\UpdateOrderRequest;
use App\Models\Address;
use App\Models\Cart;
use App\Models\CustomerDebt;
use App\Models\FinancialTransaction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Exceptions\OrderStateException;
use App\Exceptions\PaymentProviderUnavailableException;
use App\Services\Orders\OrderLifecycleService;
use App\Services\Payments\OrderQrPaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    // Пометка прихода, записанного ручной отметкой «оплачен»: при снятии
    // отметки удаляется только он
    private const MANUAL_PAYMENT_NOTE = '(смена статуса)';

    public function __construct(
        private OrderQrPaymentService $qrPayments,
        private OrderLifecycleService $lifecycle,
    ) {
    }

    // is_package: количество позиции хранится в упаковках (рулон/мешок), а
    // склад — всегда в базовой единице (метры/кг), поэтому возврат на склад
    // домножается на package_size товара.
    private function baseQuantity($product, float $quantity, bool $isPackage): float
    {
        if ($isPackage && $product && $product->package_size) {
            return $quantity * (float) $product->package_size;
        }
        return $quantity;
    }


    // Себестоимость (в позициях заказа и в подгруженных товарах) — внутренние
    // данные магазина: покупателю в своих заказах её показывать нельзя.
    private function hideCostFor($user, $orders): void
    {
        if ($user && $user->hasPermission('orders.view')) {
            return;
        }

        foreach ($orders as $order) {
            foreach ($order->items as $item) {
                $item->makeHidden('purchase_price');
                $item->product?->makeHidden(['purchase_price', 'package_purchase_price']);
            }
        }
    }

    public function index(Request $request)
    {
        $user  = Auth::user();
        $query = Order::query()->with('items.product', 'shippingAddress', 'billingAddress', 'user', 'staff')
            // Оплата подтверждена банком — админка блокирует смену оплаты
            ->withExists(['payments as paid_by_bank' => fn ($q) => Order::whereBankConfirmed($q)]);

        
        
        if ($user && $user->hasPermission('orders.view')) {
            if ($request->filled('user_id')) {
                $query->where('user_id', $request->user_id);
            }
            if ($request->filled('staff_id')) {
                $query->where('staff_id', $request->staff_id);
            }
        } elseif ($request->source === 'pos' && $user && ($user->hasPermission('pos.access') || $user->hasPermission('cashier.access'))) {
            // Кассир без orders.view видит свои кассовые чеки: в них он —
            // staff_id, а user_id это покупатель (или null у анонимной продажи)
            $query->where('staff_id', $user->id);
        } else {
            $query->where('user_id', $user->id ?? null);
        }

        // Канал продажи — колонка channel (online | pos | self_service), а не
        // текст заметки: раньше онлайн-заказ с "POS" в комментарии покупателя
        // попадал в кассовые продажи.
        if ($request->filled('source') && in_array($request->source, Order::CHANNELS, true)) {
            $query->where('channel', $request->source);
        }

        
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('payment_status')) {
            $query->where('payment_status', $request->payment_status);
        }

        if ($request->filled('payment_method')) {
            $query->where('payment_method', $request->payment_method);
        }

        // Онлайн-заказы, которые ещё ждут QR-оплаты, персоналу не нужны в
        // рабочем списке: собирать их рано, а брошенные отменит
        // orders:expire-unpaid. Админка скрывает их по умолчанию.
        if ($request->boolean('hide_awaiting_qr')) {
            // NOT(онлайн И mbank И pending И не оплачен), разложенное через OR:
            // whereNot в Laravel 8 нет, а NULL в payment_method/channel при
            // простом NOT(...) выкинул бы заказ из выдачи
            $query->where(function ($q) {
                $q->whereNull('channel')
                    ->orWhere('channel', '!=', Order::CHANNEL_ONLINE)
                    ->orWhereNull('payment_method')
                    ->orWhere('payment_method', '!=', 'mbank')
                    ->orWhere('status', '!=', 'pending')
                    ->orWhere('payment_status', 'paid');
            });
        }

        // date_from/date_to приходят от фронта уже как полные UTC-метки
        // времени — границы дня в часовом поясе пользователя переводит в UTC
        // сам браузер (см. orders.vue), поэтому здесь никаких допущений о
        // часовом поясе не делаем — только переводим метку в пояс приложения,
        // в котором хранятся created_at (Laravel пишет дату в запрос без конвертации)
        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', \Carbon\Carbon::parse($request->date_from)->setTimezone(config('app.timezone')));
        }

        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', \Carbon\Carbon::parse($request->date_to)->setTimezone(config('app.timezone')));
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->search);
            // id — целое число: LIKE по нему в Postgres падает
            // ("operator does not exist: bigint ~~ unknown"), ищем точным совпадением.
            // ilike — регистронезависимый поиск по номеру чека и имени (Postgres).
            $like = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
            $query->where(function ($q) use ($search, $like) {
                if (ctype_digit($search)) {
                    $q->orWhere('id', (int) $search);
                }
                $q->orWhere('order_number', $like, "%{$search}%")
                    ->orWhereHas('user', function ($qu) use ($search, $like) {
                        $qu->where('name', $like, "%{$search}%");
                    });
            });
        }

        $perPage = min(max((int) $request->get('per_page', 30), 1), 200);
        $orders  = $query->latest()->paginate($perPage);
        $this->hideCostFor($user, $orders->getCollection());

        return response()->json($orders);
    }

    
    public function store(StoreOrderRequest $request)
    {
        $validated = $request->validated();

        $user = Auth::user();
        $cart = Cart::where('user_id', $user->id ?? null)
            ->orWhere('session_id', session()->getId())
            ->first();

        if (! $cart || $cart->items->isEmpty()) {
            return response()->json(['message' => 'Cart is empty'], 400);
        }

        DB::beginTransaction();
        try {

            $shippingAddressId = $validated['shipping_address_id'] ?? null;
            $billingAddressId  = $validated['billing_address_id'] ?? null;

            // exists:addresses проверяет только наличие записи — без этого в
            // заказ можно было подставить id чужого адреса и прочитать его.
            foreach (array_filter([$shippingAddressId, $billingAddressId]) as $addressId) {
                if (! Address::where('id', $addressId)->where('user_id', $user->id)->exists()) {
                    throw ValidationException::withMessages(['shipping_address_id' => ['Адрес не найден.']]);
                }
            }

            if (! $shippingAddressId && isset($validated['shipping_address'])) {
                $shippingAddress = Address::create(array_merge(
                    $validated['shipping_address'],
                    ['user_id' => $user->id ?? null, 'type' => 'shipping']
                ));
                $shippingAddressId = $shippingAddress->id;
            }

            if (! $billingAddressId && isset($validated['billing_address'])) {
                $billingAddress = Address::create(array_merge(
                    $validated['billing_address'],
                    ['user_id' => $user->id ?? null, 'type' => 'billing']
                ));
                $billingAddressId = $billingAddress->id;
            }


            // Цена и остаток — на момент оформления, а не добавления в корзину:
            // иначе товар, положенный в корзину во время акции, покупался бы
            // по акционной цене и после её окончания, а склад уходил в минус.
            // lockForUpdate — два параллельных заказа не продадут один остаток.
            $lines    = [];
            $subtotal = 0;
            // Блокируем товары по возрастанию id: два заказа с теми же товарами
            // в разном порядке корзины иначе ловили взаимную блокировку (deadlock)
            foreach ($cart->items->sortBy('product_id') as $cartItem) {
                $product  = \App\Models\Product::whereKey($cartItem->product_id)->lockForUpdate()->first();
                $quantity = (float) $cartItem->quantity;

                if (! $product || ! $product->is_active) {
                    throw ValidationException::withMessages(['cart' => ["Товар «{$cartItem->product?->name}» больше не продаётся."]]);
                }
                if ($product->requiresWholeQuantity() && floor($quantity) != $quantity) {
                    throw ValidationException::withMessages(['cart' => ["Товар «{$product->name}» продаётся только целыми штуками."]]);
                }
                if ((float) $product->stock_quantity < $quantity) {
                    throw ValidationException::withMessages(['cart' => ["Недостаточно товара «{$product->name}»: в наличии {$product->stock_quantity}."]]);
                }

                $price     = (float) $product->final_price;
                // Цена не заполнена (0) — без этой проверки товар оформлялся
                // бесплатно (на 2026-10-29 таких активных товаров было 29)
                if ($price <= 0) {
                    throw ValidationException::withMessages(['cart' => ["У товара «{$product->name}» не указана цена — уберите его из корзины или свяжитесь с магазином."]]);
                }
                $lines[]   = [$product, $quantity, $price];
                $subtotal += $price * $quantity;
            }

            $tax          = 0;
            $shippingCost = 0;
            $discount     = 0;
            $couponId     = null;

            if (! empty($validated['coupon_code'])) {
                $coupon = \App\Models\Coupon::where('code', $validated['coupon_code'])->lockForUpdate()->first();

                if (! $coupon || ! $coupon->isValid() || $subtotal < $coupon->min_order_amount) {
                    throw ValidationException::withMessages(['coupon_code' => ['Купон недействителен или истёк.']]);
                }
                if ($coupon->wasUsedBy($user->id)) {
                    throw ValidationException::withMessages(['coupon_code' => ['Вы уже использовали этот купон.']]);
                }

                $discount = $coupon->type === 'percent'
                    ? $subtotal * $coupon->value / 100
                    : (float) $coupon->value;
                $discount = min($discount, $subtotal);
                $couponId = $coupon->id;
                $coupon->increment('used_count');
            }

            $total        = $subtotal + $shippingCost - $discount;

            
            $order = Order::create([
                'user_id'             => $user->id ?? null,
                'shipping_address_id' => $shippingAddressId,
                'billing_address_id'  => $billingAddressId ?? $shippingAddressId,
                'status'              => 'pending',
                'payment_status'      => 'pending',
                'payment_method'      => $validated['payment_method'] ?? null,
                'subtotal'            => $subtotal,
                'tax'                 => $tax,
                'shipping_cost'       => $shippingCost,
                'discount'            => $discount,
                'coupon_id'           => $couponId,
                'total'               => $total,
                'notes'               => $validated['notes'] ?? null,
                'channel'             => Order::CHANNEL_ONLINE,
            ]);


            foreach ($lines as [$product, $quantity, $price]) {
                OrderItem::create([
                    'order_id'       => $order->id,
                    'product_id'     => $product->id,
                    'product_name'   => $product->name,
                    'product_sku'    => $product->sku,
                    'quantity'       => $quantity,
                    'purchase_price' => $product->purchase_price,
                    'price'          => $price,
                    'total'          => $price * $quantity,
                ]);


                $product->decrement('stock_quantity', $quantity);
                $product->update(['in_stock' => $product->stock_quantity > 0]);
                $product->increment('sales_count', $quantity);
            }


            $cart->items()->delete();


            // Продажа в долг — решение персонала, а не покупателя: иначе любой
            // зарегистрированный клиент оформлял бы себе товар в кредит.
            if ($request->boolean('is_debt') && $user && $user->hasPermission('orders.edit')) {
                $initialPayment = min((float) ($validated['initial_payment'] ?? 0), (float) $total);
                $debt           = CustomerDebt::create([
                    'user_id'          => $user->id,
                    'order_id'         => $order->id,
                    'total_amount'     => $total,
                    'paid_amount'      => $initialPayment,
                    'remaining_amount' => $total - $initialPayment,
                    'due_date'         => $validated['due_date'] ?? null,
                    'status'           => ($total - $initialPayment) <= 0 ? 'paid' : 'active',
                ]);

                if ($initialPayment > 0) {
                    FinancialTransaction::create([
                        'user_id'        => auth()->id(),
                        'type'           => 'income',
                        'amount'         => $initialPayment,
                        'category'       => 'sale',
                        'trackable_type' => Order::class,
                        'trackable_id'   => $order->id,
                        'description'    => "Частичная оплата заказа #{$order->id} (в долг)",
                    ]);
                }
            } elseif ($order->payment_status === 'paid') {
                
                FinancialTransaction::create([
                    'user_id'        => auth()->id(),
                    'type'           => 'income',
                    'amount'         => $total,
                    'category'       => 'sale',
                    'trackable_type' => Order::class,
                    'trackable_id'   => $order->id,
                    'description'    => "Продажа товара (предоплата), заказ #{$order->id}",
                    'payment_method' => $order->payment_method ?? 'cash',
                ]);
            }

            DB::commit();

            // Динамический QR (GoPay) — сразу, чтобы покупатель увидел его на
            // странице заказа, но уже ПОСЛЕ коммита: внешний HTTP-вызов внутри
            // транзакции держал блокировки товаров, а при откате оставлял в
            // GoPay платёж, о котором мы не знаем. Если GoPay не ответил, заказ
            // остаётся — QR выпускается повторно со страницы заказа.
            $paymentError = null;
            if (($validated['payment_method'] ?? null) === 'mbank') {
                try {
                    $this->qrPayments->createQrPayment($order);
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::error('QR payment creation failed: ' . $e->getMessage(), ['order_id' => $order->id]);
                    $paymentError = 'Заказ оформлен, но QR для оплаты создать не удалось. Получите новый QR на странице заказа.';
                }
            }

            $order->load('items.product', 'shippingAddress', 'billingAddress', 'payments');

            // QR-заказ для персонала появляется только после оплаты: иначе
            // админка печатала чек на сборку по заказу, который могут так и
            // не оплатить. Событие для него шлёт PaymentSettlementService.
            if ($order->payment_method !== 'mbank') {
                try {
                    event(new \App\Events\NewOrderPlaced($order));
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('Broadcast failed (NewOrderPlaced): ' . $e->getMessage());
                }
            }

            $order->setAttribute('payment_deadline', $this->paymentDeadline($order));
            $this->hideCostFor($user, [$order]);

            $payload = $order->toArray();
            if ($paymentError) {
                $payload['payment_error'] = $paymentError;
            }

            return response()->json($payload, 201);
        } catch (ValidationException $e) {
            DB::rollBack();
            throw $e;
        } catch (\Exception $e) {
            DB::rollBack();
            // Текст исключения (SQL, пути) клиенту не отдаём — только в лог
            \Illuminate\Support\Facades\Log::error('Order creation failed: ' . $e->getMessage(), ['exception' => $e]);
            return response()->json(['message' => 'Не удалось оформить заказ. Попробуйте ещё раз.'], 500);
        }
    }

    
    public function show(Order $order)
    {
        $user = Auth::user();

        if ($user && ! $user->hasPermission('orders.view') && $order->user_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $order->load('items.product', 'shippingAddress', 'billingAddress', 'payments');
        $order->setAttribute('paid_by_bank', $order->isPaidByBank());
        $order->setAttribute('payment_deadline', $this->paymentDeadline($order));
        $this->hideCostFor($user, [$order]);
        return response()->json($order);
    }

    // Когда неоплаченный QR-заказ отменится сам (orders:expire-unpaid) —
    // покупатель видит срок на странице заказа. Команда идёт раз в 15 минут,
    // поэтому фактическая отмена может прийти чуть позже этого времени.
    private function paymentDeadline(Order $order): ?string
    {
        if ($order->channel !== Order::CHANNEL_ONLINE || $order->payment_method !== 'mbank'
            || $order->status !== 'pending' || $order->payment_status === 'paid') {
            return null;
        }

        return $order->created_at
            ?->copy()
            ->addMinutes(\App\Console\Commands\ExpireUnpaidOrders::onlineTtlMinutes())
            ->toIso8601String();
    }

    
    public function update(UpdateOrderRequest $request, Order $order)
    {
        if (!Auth::user()->hasPermission('orders.edit')) {
            return response()->json(['message' => 'Forbidden. Missing permission: orders.edit'], 403);
        }

        $validated = $request->validated();

        $oldStatus        = $order->status;
        $oldPaymentStatus = $order->payment_status;
        $newStatus        = $validated['status'] ?? $oldStatus;
        $newPaymentStatus = $validated['payment_status'] ?? $oldPaymentStatus;

        $activeStatuses = OrderLifecycleService::ACTIVE_STATUSES;
        $cancelStatuses = ['cancelled', 'refunded'];

        // Оплату подтвердил банк — статус и способ оплаты не меняются вручную.
        // Тот же статус/способ (форма шлёт их всегда) — не изменение
        $paymentStatusChanged = array_key_exists('payment_status', $validated) && $validated['payment_status'] !== $oldPaymentStatus;
        $paymentMethodChanged = array_key_exists('payment_method', $validated) && $validated['payment_method'] !== $order->payment_method;

        // Единственное разрешённое изменение — вернуть «Оплачено», если статус
        // успели снять вручную до этой блокировки (иначе заказ застрял бы)
        $restoringPaid = $paymentStatusChanged && $validated['payment_status'] === 'paid' && ! $paymentMethodChanged;

        if (($paymentStatusChanged || $paymentMethodChanged) && ! $restoringPaid && $order->isPaidByBank()) {
            return response()->json([
                'message' => 'Заказ оплачен через банк (QR GoPay) — статус и способ оплаты изменить нельзя. Для возврата денег отмените заказ или оформите возврат.',
            ], 422);
        }

        // QR-оплату отмечает только банк: вручную «QR» без поступления денег —
        // это продажа, которой нет в выписке
        if ($paymentMethodChanged && in_array($validated['payment_method'], Order::BANK_PAYMENT_METHODS, true)) {
            return response()->json([
                'message' => 'Оплату по QR отмечает банк автоматически — выберите другой способ оплаты.',
            ], 422);
        }

        // Отмена через смену статуса — та же операция, что кнопка «Отменить»
        // (остаток, купон, возврат денег, долг). Раньше здесь была своя копия
        // логики, которая не создавала возврат денег за оплаченный заказ.
        if ($newStatus === 'cancelled' && in_array($oldStatus, $activeStatuses, true)) {
            try {
                $order = $this->lifecycle->cancel($order, Auth::id());
            } catch (OrderStateException $e) {
                return response()->json(['message' => $e->getMessage()], 409);
            } catch (PaymentProviderUnavailableException $e) {
                return response()->json(['message' => $e->getMessage()], 503);
            }

            $otherFields = Arr::except($validated, ['status', 'payment_status']);
            if ($otherFields) {
                $order->update($otherFields);
            }

            $this->broadcastStatus($order);

            return response()->json($order->load('items.product'));
        }

        $reactivating = in_array($oldStatus, $cancelStatuses, true) && in_array($newStatus, $activeStatuses, true);

        // До отмены был частичный возврат (returnItems): отмена отметила все
        // позиции «возвращёнными», и сколько вернули раньше — уже не узнать.
        // Восстановление списало бы со склада и возвращённое покупателем, а
        // удаление записей «refund» стёрло бы реально выданные из кассы деньги
        if ($reactivating && FinancialTransaction::where('trackable_type', Order::class)
            ->where('trackable_id', $order->id)
            ->where('category', 'refund')
            ->where('description', 'like', 'Частичный возврат%')
            ->exists()) {
            return response()->json([
                'message' => 'По заказу был частичный возврат товара — восстановить его нельзя. Оформите новую продажу.',
            ], 422);
        }

        // Онлайн-заказ при восстановлении снова резервирует товар — его ещё
        // нет у покупателя, поэтому без остатка восстанавливать нельзя.
        // Кассовые продажи (товар уже на руках) восстанавливаются как раньше.
        if ($reactivating && $order->channel === Order::CHANNEL_ONLINE) {
            foreach ($order->items()->with('product')->get() as $item) {
                $product = $item->product;
                $needed  = $this->baseQuantity($product, (float) $item->quantity, (bool) $item->is_package);
                if ($product && (float) $product->stock_quantity < $needed) {
                    return response()->json([
                        'message' => "Недостаточно товара «{$product->name}» для восстановления заказа: в наличии {$product->stock_quantity}.",
                    ], 422);
                }
            }
        }

        $order = DB::transaction(function () use ($validated, $order, $oldStatus, $newStatus, $oldPaymentStatus, $newPaymentStatus, $activeStatuses, $reactivating) {
            $order->update($validated);

            // Отметили оплаченным вручную — приход только на ещё не полученную
            // часть: первый взнос и выплаты по долгу уже записаны. Раньше приход
            // заказа перезаписывался на всю сумму (первый взнос терялся), а при
            // выплатах по долгу остаток не записывался вовсе
            if ($newPaymentStatus === 'paid' && $oldPaymentStatus !== 'paid') {
                $debt = CustomerDebt::where('order_id', $order->id)->where('status', '!=', 'cancelled')->first();
                $outstanding = round($debt
                    ? (float) $debt->remaining_amount
                    : (float) $order->total - $this->lifecycle->receivedAmount($order), 2);

                if ($outstanding > 0) {
                    FinancialTransaction::create([
                        'user_id'        => Auth::id(),
                        'type'           => 'income',
                        'amount'         => $outstanding,
                        'category'       => 'sale',
                        'trackable_type' => Order::class,
                        'trackable_id'   => $order->id,
                        'description'    => "Оплата заказа #{$order->order_number} " . self::MANUAL_PAYMENT_NOTE,
                        'payment_method' => $order->payment_method ?? 'cash',
                    ]);
                }
            }

            if ($newPaymentStatus === 'paid') {
                CustomerDebt::where('order_id', $order->id)
                    ->whereNotIn('status', ['paid', 'cancelled'])
                    ->update([
                        'status'           => 'paid',
                        'remaining_amount' => 0,
                        'paid_amount'      => DB::raw('total_amount'),
                    ]);
            }

            // Выдан без оплаты — долг покупателя
            if ($newStatus === 'delivered' && $newPaymentStatus !== 'paid' && $order->user_id) {
                CustomerDebt::firstOrCreate(
                    ['order_id' => $order->id],
                    [
                        'user_id'          => $order->user_id,
                        'total_amount'     => $order->total,
                        'paid_amount'      => 0,
                        'remaining_amount' => $order->total,
                        'status'           => 'active',
                    ]
                );
            }

            // Оплату сняли — убираем только приход, записанный ручной отметкой
            // «оплачен». Первый взнос, выплаты по долгу и оплата через банк —
            // реальные деньги; раньше удалялся весь приход по заказу, а долг
            // оставался «внесено = вся сумма» с нулевым остатком
            if ($newPaymentStatus === 'pending' && $oldPaymentStatus === 'paid') {
                FinancialTransaction::where('trackable_type', Order::class)
                    ->where('trackable_id', $order->id)
                    ->where('type', 'income')
                    ->where('category', 'sale')
                    ->where('description', 'like', '%' . self::MANUAL_PAYMENT_NOTE)
                    ->delete();

                $received = $this->lifecycle->receivedAmount($order);
                $debt     = CustomerDebt::where('order_id', $order->id)->where('status', '!=', 'cancelled')->first();
                $total    = $debt ? (float) $debt->total_amount : (float) $order->total;
                $paid     = round(max(0, min($received, $total)), 2);
                $debtData = [
                    'paid_amount'      => $paid,
                    'remaining_amount' => round($total - $paid, 2),
                    'status'           => $paid >= $total ? 'paid' : ($paid > 0 ? 'partial' : 'active'),
                ];

                if ($debt) {
                    $debt->update($debtData);
                } elseif ($order->user_id) {
                    CustomerDebt::create($debtData + [
                        'user_id'      => $order->user_id,
                        'order_id'     => $order->id,
                        'total_amount' => $total,
                    ]);
                }
            }

            if ($newStatus === 'shipped' && $oldStatus !== 'shipped') {
                $order->update(['shipped_at' => now()]);
            }
            if ($newStatus === 'delivered' && $oldStatus !== 'delivered') {
                $order->update(['delivered_at' => now()]);
            }

            // Полный возврат: товар на склад, купон, деньги покупателю (наличными)
            if ($newStatus === 'refunded' && in_array($oldStatus, $activeStatuses, true)) {
                $this->lifecycle->returnStock($order);
                $this->lifecycle->releaseCoupon($order);
                $this->lifecycle->recordRefund($order, Auth::id(), "Полный возврат средств за заказ #{$order->order_number}");
                $this->lifecycle->cancelDebt($order);
            }

            // Восстановление отменённого/возвращённого заказа
            if ($reactivating) {
                foreach ($order->items()->with('product')->get() as $item) {
                    if ($item->product) {
                        $item->product->decrement('stock_quantity', $this->baseQuantity($item->product, (float) $item->quantity, (bool) $item->is_package));
                    }
                    $item->update(['refunded_quantity' => 0]);
                }

                if ($order->coupon_id) {
                    \App\Models\Coupon::whereKey($order->coupon_id)->increment('used_count');
                }

                FinancialTransaction::where('trackable_type', Order::class)
                    ->where('trackable_id', $order->id)
                    ->where('category', 'refund')
                    ->delete();

                // Отмена обнулила остаток — восстанавливаем его из суммы и
                // внесённого (возвраты выше удалены, деньги снова «получены»)
                CustomerDebt::where('order_id', $order->id)
                    ->where('status', 'cancelled')
                    ->update([
                        'remaining_amount' => DB::raw('CASE WHEN total_amount > paid_amount THEN total_amount - paid_amount ELSE 0 END'),
                        'status'           => DB::raw("CASE WHEN total_amount <= paid_amount THEN 'paid' WHEN paid_amount > 0 THEN 'partial' ELSE 'active' END"),
                    ]);
            }

            return $order;
        });

        // Рассылка — после коммита: внутри транзакции HTTP-вызов к Soketi
        // держал бы блокировки, а слушатели получали бы ещё не записанное
        $this->broadcastStatus($order);

        return response()->json($order->load('items.product'));
    }

    private function broadcastStatus(Order $order): void
    {
        try {
            event(new \App\Events\OrderStatusUpdated($order));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Broadcast failed (OrderStatusUpdated): ' . $e->getMessage());
        }
    }


    // Новый QR для оплаты заказа — нужен, если предыдущий (живёт 5 минут)
    // истёк, а покупатель так и не успел оплатить.
    public function generatePaymentQr(Order $order)
    {
        $user = Auth::user();
        if ($user && $order->user_id !== $user->id && ! $user->hasPermission('orders.view')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Тексты показываются покупателю на странице заказа как есть
        if ($order->payment_status === 'paid') {
            return response()->json(['message' => 'Заказ уже оплачен.'], 400);
        }

        if (in_array($order->status, ['cancelled', 'refunded'])) {
            return response()->json(['message' => 'Заказ отменён: время на оплату истекло или он отменён вручную. Оформите заказ заново.'], 400);
        }

        // Старый QR гасим у провайдера до выпуска нового — иначе по обоим
        // можно заплатить и деньги придут дважды
        try {
            if (! $this->qrPayments->cancelPendingPayments($order)) {
                return response()->json(['message' => 'Заказ уже оплачен по предыдущему QR.', 'order' => $order->fresh('payments')], 409);
            }
        } catch (PaymentProviderUnavailableException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }

        try {
            $payment = $this->qrPayments->createQrPayment($order);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('QR payment creation failed: ' . $e->getMessage(), ['order_id' => $order->id]);
            return response()->json(['message' => 'Не удалось создать QR для оплаты. Попробуйте позже.'], 500);
        }

        // Полный заказ, как в show(): страница заказа заменяет им свой — без
        // items/адреса таблица товаров и доставка пропадали до опроса
        $fresh = $order->fresh(['items.product', 'shippingAddress', 'billingAddress', 'payments']);
        $fresh->setAttribute('paid_by_bank', $fresh->isPaidByBank());
        $fresh->setAttribute('payment_deadline', $this->paymentDeadline($fresh));
        $this->hideCostFor($user, [$fresh]);

        return response()->json([
            'order'   => $fresh,
            'payment' => $payment,
        ]);
    }

    public function cancel(Order $order)
    {
        $user = Auth::user();

        $isStaff = $user->hasPermission('orders.edit');

        if ($order->user_id !== $user->id && ! $isStaff) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Покупатель сам отменяет только ещё не оплаченный и не взятый в работу
        // заказ. Отмена оплаченного означает возврат денег — это решает персонал.
        // Проверка повторяется в OrderLifecycleService под блокировкой.
        if (! $isStaff && ($order->status !== 'pending' || $order->payment_status === 'paid')) {
            return response()->json(['message' => 'Этот заказ уже оплачен или в работе — для отмены свяжитесь с магазином.'], 400);
        }

        try {
            $order = $this->lifecycle->cancel(
                $order,
                $user->id,
                $isStaff ? ['pending', 'processing'] : ['pending'],
                $isStaff,
            );
        } catch (OrderStateException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (PaymentProviderUnavailableException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }

        try {
            event(new \App\Events\OrderStatusUpdated($order));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Broadcast failed (OrderStatusUpdated): ' . $e->getMessage());
        }

        return response()->json(['message' => 'Order cancelled successfully']);
    }

    
    public function returnItems(Request $request, Order $order)
    {
        if (!Auth::user()->hasPermission('orders.edit')) {
            return response()->json(['message' => 'Forbidden. Missing permission: orders.edit'], 403);
        }

        $validated = $request->validate([
            'items'            => 'required|array|min:1',
            'items.*.id'       => 'required|exists:order_items,id',
            'items.*.quantity' => 'required|numeric|min:0.001',
        ]);

        return DB::transaction(function () use ($validated, $order) {
            // Блокировки: два одновременных возврата одной позиции не вернут её дважды
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (in_array($order->status, ['cancelled', 'refunded'], true)) {
                throw ValidationException::withMessages(['items' => ['Заказ уже отменён — возвращать нечего.']]);
            }

            // Скидка чека (купон + ручная) распределяется по позициям
            // пропорционально: покупателю возвращается то, что он реально
            // заплатил за товар, а не цена до скидки
            $ratio = (float) $order->subtotal > 0 ? (float) $order->total / (float) $order->subtotal : 1.0;

            $refundAmount = 0.0;

            foreach ($validated['items'] as $returnItem) {
                $orderItem = OrderItem::where('order_id', $order->id)
                    ->whereKey($returnItem['id'])
                    ->lockForUpdate()
                    ->firstOrFail();

                $quantity          = (float) $returnItem['quantity'];
                $availableToReturn = (float) $orderItem->quantity - (float) $orderItem->refunded_quantity;

                if ($quantity > $availableToReturn + 0.0005) {
                    throw ValidationException::withMessages(['items' => [
                        "Нельзя вернуть {$quantity} товара «{$orderItem->product_name}». Доступно для возврата: {$availableToReturn}.",
                    ]]);
                }

                $product = $orderItem->product;
                if ($product && ! $orderItem->is_package && $product->requiresWholeQuantity() && floor($quantity) != $quantity) {
                    throw ValidationException::withMessages(['items' => [
                        "Товар «{$orderItem->product_name}» возвращается только целыми штуками.",
                    ]]);
                }

                $orderItem->increment('refunded_quantity', $quantity);

                if ($product) {
                    $product->increment('stock_quantity', $this->baseQuantity($product, $quantity, (bool) $orderItem->is_package));
                    $product->update(['in_stock' => $product->stock_quantity > 0]);
                }

                $refundAmount += $quantity * (float) $orderItem->price * $ratio;
            }

            $refundAmount = round($refundAmount, 2);

            // Сначала уменьшаем долг покупателя (за неоплаченное денег не
            // возвращают), остаток — наличными, но не больше полученной выручки
            $debtReduction = 0.0;
            $debt = CustomerDebt::where('order_id', $order->id)->lockForUpdate()->first();
            if ($debt && $debt->status === 'cancelled') {
                $debt = null;
            }
            if ($debt) {
                $debtReduction = min((float) $debt->remaining_amount, $refundAmount);
            }

            // Наличными — не больше, чем покупатель внёс за заказ, включая
            // выплаты по долгу (receivedAmount учитывает и их)
            $cashRefund = round(min($refundAmount - $debtReduction, max(0, $this->lifecycle->receivedAmount($order))), 2);

            if ($debt) {
                // Сумма долга = внесено + остаток: возврат уменьшает остаток,
                // а выданное наличными — внесённое
                $debt->remaining_amount = round(max(0, (float) $debt->remaining_amount - $debtReduction), 2);
                $debt->total_amount     = round(max(0, (float) $debt->total_amount - $refundAmount), 2);
                $debt->paid_amount      = round(max(0, (float) $debt->paid_amount - max(0, $cashRefund)), 2);
                if ((float) $debt->remaining_amount == 0.0) {
                    $debt->status = 'paid';
                }
                $debt->save();
            }

            if ($cashRefund > 0) {
                FinancialTransaction::create([
                    'user_id'        => auth()->id(),
                    'type'           => 'expense',
                    'amount'         => $cashRefund,
                    'category'       => 'refund',
                    'trackable_type' => Order::class,
                    'trackable_id'   => $order->id,
                    'description'    => "Частичный возврат товаров по заказу #{$order->order_number}",
                    // Возврат денег покупателю — только наличными
                    'payment_method' => 'cash',
                ]);
            }

            return response()->json([
                'message'       => 'Items returned successfully',
                'refund_amount' => $cashRefund,
                'debt_reduced'  => round($debtReduction, 2),
                'order'         => $order->refresh()->load('items.product'),
            ]);
        });
    }

    
    public function trackByNumber($orderNumber)
    {
        $query = Order::with(['items.product', 'shippingAddress', 'user', 'staff']);

        // Ищем строго по номеру чека (order_number)
        $order = $query->where('order_number', (string) $orderNumber)->first();

        if (! $order) {
            return response()->json(['message' => 'Заказ с таким номером не найден'], 404);
        }

        // Роут публичный, а номера POS-чеков идут подряд — их легко перебрать.
        // Полный заказ (клиент, адрес, кассир, себестоимость) нужен только
        // персоналу: касса ищет по номеру чека для оформления возврата.
        $viewer = auth('sanctum')->user();
        if ($viewer && ($viewer->hasPermission('orders.view') || $viewer->hasPermission('cashier.access') || $viewer->hasPermission('pos.access'))) {
            return response()->json($order);
        }

        return response()->json([
            'id'             => $order->id,
            // для подписки на живые обновления статуса (канал order.{uuid})
            'uuid'           => $order->uuid,
            'order_number'   => $order->order_number,
            'status'         => $order->status,
            'payment_status' => $order->payment_status,
            'total'          => $order->total,
            'currency'       => $order->currency,
            'created_at'     => $order->created_at,
            'shipped_at'     => $order->shipped_at,
            'delivered_at'   => $order->delivered_at,
            'shipping_address' => $order->shippingAddress ? ['address' => $order->shippingAddress->city] : null,
            'items'          => $order->items->map(fn ($item) => [
                'product_name' => $item->product_name,
                'quantity'     => $item->quantity,
                'price'        => $item->price,
                'total'        => $item->total,
            ]),
        ]);
    }
}
