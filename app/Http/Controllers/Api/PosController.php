<?php
namespace App\Http\Controllers\Api;

use App\Exceptions\OrderStateException;
use App\Exceptions\PaymentProviderUnavailableException;
use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\FinancialTransaction;
use App\Models\Order;
use App\Models\Product;
use App\Services\Pos\PosSaleService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class PosController extends Controller
{
    public function __construct(private PosSaleService $sales)
    {
    }

    public function summary(Request $request)
    {
        $today   = now()->startOfDay();
        $staffId = $request->get('staff_id');

        // Кассир без права reports.view видит только свою выручку,
        // даже если попробует запросить чужой staff_id напрямую через API.
        if (!$request->user()->hasPermission('reports.view')) {
            $staffId = $request->user()->id;
        }

        $cashQuery = FinancialTransaction::where('type', 'income')
            ->whereDate('created_at', $today)
            ->where('category', PosSaleService::CATEGORY_CASH);

        // QR GoPay; «Перевод» — старые продажи до перехода на QR
        $qrQuery = FinancialTransaction::where('type', 'income')
            ->whereDate('created_at', $today)
            ->where(function ($q) {
                $q->where('category', PosSaleService::CATEGORY_QR)
                    ->orWhere('category', 'like', '%POS - Перевод%');
            });

        if ($staffId) {
            $cashQuery->whereHasMorph('trackable', [Order::class], function ($q) use ($staffId) {
                $q->where('staff_id', $staffId);
            });
            $qrQuery->whereHasMorph('trackable', [Order::class], function ($q) use ($staffId) {
                $q->where('staff_id', $staffId);
            });
        }

        $cashConfirmed = (float) $cashQuery->sum('amount');
        $qrConfirmed   = (float) $qrQuery->sum('amount');

        $salesCountQuery = Order::whereDate('created_at', $today)
            ->where('channel', Order::CHANNEL_POS)
            ->whereNotIn('status', ['cancelled']);

        if ($staffId) {
            $salesCountQuery->where('staff_id', $staffId);
        }

        return response()->json([
            'cash_total'  => $cashConfirmed,
            'qr_total'    => $qrConfirmed,
            'total'       => $cashConfirmed + $qrConfirmed,
            'sales_count' => $salesCountQuery->count(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'items'              => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity'   => 'required|numeric|min:0.001',
            'items.*.is_package' => 'nullable|boolean',
            'items.*.price'      => 'required|numeric|min:0',
            'items.*.barcode'    => 'nullable|string|max:32',
            'user_id'            => 'nullable|exists:users,id',
            'cash_amount'        => 'required|numeric|min:0',
            // QR-часть смешанной оплаты (GoPay). Ручной «перевод» больше не
            // принимается — его нельзя проверить.
            'qr_amount'          => 'nullable|numeric|min:0',
            'is_debt'            => 'boolean',
            'due_date'           => 'nullable|date|after:today',
            // Скидку касса не присылает готовой суммой: купон сервер
            // пересчитывает сам по коду, ручная скидка проверяется по правам.
            'coupon_code'        => 'nullable|string|max:64',
            'manual_discount'    => 'nullable|numeric|min:0',
        ]);

        if ((float) $request->input('transfer_amount', 0) > 0) {
            return response()->json([
                'message' => 'Оплата переводом больше не принимается — используйте QR. Обновите страницу кассы.',
            ], 422);
        }

        try {
            $result = $this->sales->create($validated, $request->user());
        } catch (PaymentProviderUnavailableException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }

        return response()->json($this->presentSale($result['order'], $result['payment']), 201);
    }

    // Экран ожидания QR: опрос состояния; ?check=1 — спросить и провайдера
    public function saleStatus(Request $request, Order $order)
    {
        $this->authorizeSale($request, $order);

        $order = $this->sales->status($order, $request->boolean('check'));

        return response()->json($this->presentSale($order, $order->payments->whereIn('status', ['pending', 'processing'])->last()));
    }

    public function cancelSale(Request $request, Order $order)
    {
        $this->authorizeSale($request, $order);

        if ($order->status !== 'pending') {
            return response()->json(['message' => 'Продажа уже не ожидает оплаты.'], 409);
        }

        try {
            $order = $this->sales->cancelPending($order);
        } catch (OrderStateException $e) {
            return response()->json(['message' => $e->getMessage(), 'order' => $this->presentSale($order->fresh(['items.product', 'user']), null)], 409);
        } catch (PaymentProviderUnavailableException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }

        return response()->json($this->presentSale($order->load(['items.product', 'user']), null));
    }

    public function regenerateSaleQr(Request $request, Order $order)
    {
        $this->authorizeSale($request, $order);

        try {
            $payment = $this->sales->regenerateQr($order);
        } catch (OrderStateException $e) {
            return response()->json(['message' => $e->getMessage(), 'order' => $this->presentSale($order->fresh(['items.product', 'user']), null)], 409);
        } catch (PaymentProviderUnavailableException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }

        return response()->json($this->presentSale($order->fresh(['items.product', 'user']), $payment));
    }

    // Заказ кассы + данные QR для экрана оплаты (qr_value, expires_at)
    private function presentSale(Order $order, $payment): array
    {
        $data = $order->toArray();
        $data['awaiting_payment'] = $order->status === 'pending';
        $data['qr_payment'] = $payment
            ? array_merge(
                json_decode((string) $payment->payment_details, true) ?: [],
                ['id' => $payment->id, 'amount' => (float) $payment->amount]
            )
            : null;

        return $data;
    }

    // Продажа кассы — её кассир или персонал с правом на заказы
    private function authorizeSale(Request $request, Order $order): void
    {
        $user = $request->user();

        abort_if($order->channel !== Order::CHANNEL_POS, 404);
        abort_if($order->staff_id !== $user->id && ! $user->hasPermission('orders.edit'), 403);
    }

    public function searchProducts(Request $request)
    {
        $search = trim((string) $request->get('q'));
        if (! $search) {
            return response()->json([]);
        }

        $query = Product::where('is_active', true);

        // На кассе критично не промахнуться по опечатке кассира — та же логика
        // нечёткого поиска (триграммы + Левенштейн), что и в каталоге
        // (ProductController::buildIndexPayload), доступна только на Postgres
        // (pg_trgm/fuzzystrmatch). В тестах/sqlite падаем обратно на LIKE.
        if (DB::connection()->getDriverName() === 'pgsql') {
            $hasDirectMatch = (clone $query)->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                    ->orWhere('sku', 'ilike', "%{$search}%");
            })->exists();

            if ($hasDirectMatch) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'ilike', "%{$search}%")
                        ->orWhere('sku', 'ilike', "%{$search}%");
                })->orderByRaw('GREATEST(similarity(name, ?), word_similarity(?, name)) desc', [$search, $search]);
            } else {
                $query->whereRaw('GREATEST(similarity(name, ?), word_similarity(?, name)) >= 0.25', [$search, $search])
                    ->orderByRaw(
                        '(SELECT MIN(levenshtein(lower(w), lower(?))) FROM unnest(string_to_array(name, \' \')) AS w) asc',
                        [$search]
                    );
            }
        } else {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%");
            });
        }

        return response()->json($query->limit(10)->get());
    }

    public function getAllProducts()
    {
        $products = Product::where('is_active', true)
            ->get(['id', 'name', 'sku', 'sale_price', 'price', 'stock_quantity', 'unit', 'package_unit', 'package_size', 'package_price', 'is_weighted', 'min_weight', 'max_weight']);

        return response()->json($products);
    }

    public function getStaff()
    {
        // Персонал — любая роль, кроме покупателя (как в middleware IsStaff):
        // роли динамические, новые не должны выпадать из списка
        $staff = \App\Models\User::where('role', '!=', 'user')->get(['id', 'name', 'role']);
        return response()->json($staff);
    }

    // Поиск покупателя для продажи в долг. Кассир не имеет доступа к /users
    // (только superadmin), поэтому отдаём здесь лишь поля, нужные кассе.
    public function searchCustomers(Request $request)
    {
        $search = trim((string) $request->get('search'));
        if (mb_strlen($search) < 2) {
            return response()->json(['data' => []]);
        }

        $like = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
        // Номер хранится как +996XXXXXXXXX, а кассир вводит как привык:
        // «0555 12 34», «555-123» — ищем по цифрам без ведущего 0/996
        $digits = preg_replace('/^(996|0)/', '', preg_replace('/\D+/', '', $search));

        $customers = \App\Models\User::where('role', 'user')
            ->where(function ($q) use ($search, $like, $digits) {
                $q->where('name', $like, "%{$search}%")
                    ->orWhere('email', $like, "%{$search}%")
                    ->orWhere('phone', $like, "%{$search}%");
                if (strlen($digits) >= 3) {
                    $q->orWhere('phone', 'like', "%{$digits}%");
                }
            })
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'uuid', 'name', 'phone', 'email']);

        return response()->json(['data' => $customers]);
    }

    // Новый покупатель с кассы: имя и номер. Он сразу может войти на сайт по
    // этому номеру через SMS и увидит свои покупки и долги. Если номер уже
    // есть — возвращается существующий клиент (кассир не создаст дубль).
    public function createCustomer(Request $request)
    {
        $validated = $request->validate([
            'name'  => 'required|string|max:255',
            'phone' => 'required|string|max:32',
        ]);

        $phone = \App\Support\Phone::normalize($validated['phone']);
        if ($phone === null) {
            return response()->json([
                'message' => 'Введите номер полностью: 9 цифр после +996.',
                'errors'  => ['phone' => ['Введите номер полностью: 9 цифр после +996.']],
            ], 422);
        }

        $existing = \App\Models\User::withTrashed()->where('phone', $phone)->first();
        if ($existing) {
            if ($existing->trashed() || $existing->role !== 'user') {
                return response()->json([
                    'message' => 'Этот номер уже занят и не может быть клиентом.',
                    'errors'  => ['phone' => ['Этот номер уже занят и не может быть клиентом.']],
                ], 422);
            }

            return response()->json(['customer' => $existing->only(['id', 'uuid', 'name', 'phone', 'email']), 'existing' => true]);
        }

        $customer = \App\Models\User::create([
            'name'     => trim($validated['name']),
            'phone'    => $phone,
            'role'     => 'user',
            'password' => \Illuminate\Support\Facades\Hash::make(\Illuminate\Support\Str::random(40)),
        ]);

        return response()->json(['customer' => $customer->only(['id', 'uuid', 'name', 'phone', 'email']), 'existing' => false], 201);
    }

    // Покупатель собрал корзину сканером на /tsd и получил QR (см.
    // CartController::checkoutQr). Здесь касса читает актуальное содержимое
    // той же корзины по cart_id из токена — и сразу забирает товары
    // (очищает корзину), чтобы повторный скан того же QR не задвоил продажу.
    public function scanCheckout(Request $request)
    {
        $request->validate(['token' => 'required|string']);

        try {
            $payload = json_decode(Crypt::decryptString($request->token), true);
        } catch (DecryptException $e) {
            return response()->json(['message' => 'QR-код недействителен'], 422);
        }

        if (! is_array($payload) || empty($payload['cart_id']) || empty($payload['exp'])) {
            return response()->json(['message' => 'QR-код повреждён'], 422);
        }

        if ($payload['exp'] < now()->timestamp) {
            return response()->json(['message' => 'QR-код истёк — попросите покупателя пересканировать товары'], 410);
        }

        $cart = Cart::with('items.product')->find($payload['cart_id']);
        if (! $cart || $cart->items->isEmpty()) {
            return response()->json(['message' => 'Корзина пуста или уже использована'], 404);
        }

        $items = $cart->items
            ->filter(fn ($item) => $item->product && $item->product->is_active)
            ->map(function ($item) {
                $product = $item->product;
                return [
                    'product_id'     => $product->id,
                    'sku'            => $product->sku,
                    'name'           => $product->name,
                    'price'          => $product->final_price,
                    'quantity'       => min($item->quantity, max($product->stock_quantity, 0)),
                    'stock_quantity' => $product->stock_quantity,
                    'image_url'      => $product->image_url,
                ];
            })
            ->filter(fn ($item) => $item['quantity'] > 0)
            ->values();

        $cart->items()->delete();

        return response()->json(['items' => $items]);
    }
}
