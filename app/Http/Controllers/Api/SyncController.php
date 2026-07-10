<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Models\FinancialTransaction;
use Illuminate\Support\Facades\Log;

class SyncController extends Controller
{
    /**
     * Pull catalog data for local POS
     */
    public function pull(Request $request)
    {
        $since = $request->input('since');

        $categories = Category::when($since, function($q) use ($since) {
            return $q->where('updated_at', '>', $since);
        })->get();

        $products = Product::when($since, function($q) use ($since) {
            return $q->where('updated_at', '>', $since);
        })->get();

        // Пользователи: персонал для офлайн-входа + клиенты для продаж в долг
        $users = \App\Models\User::when($since, function($q) use ($since) {
            return $q->where('updated_at', '>', $since);
        })->get(['id', 'uuid', 'name', 'email', 'phone', 'role', 'updated_at']);

        // Купоны: офлайн-валидация на кассе
        $coupons = \App\Models\Coupon::when($since, function($q) use ($since) {
            return $q->where('updated_at', '>', $since);
        })->get();

        // Настройки магазина: правила кассы (pos_allow_price_change, pos_allow_debt и т.д.)
        $settings = \App\Models\Setting::all()->pluck('value', 'key');

        // Заказы: история для офлайн-просмотра и повторной печати чека.
        // Первичная синхронизация — только последние 60 дней, дальше инкрементально.
        $orders = Order::with(['items.product', 'user:id,uuid,name,phone', 'staff:id,uuid,name'])
            ->when($since,
                fn($q) => $q->where('updated_at', '>', $since),
                fn($q) => $q->where('created_at', '>', now()->subDays(60))
            )
            ->latest()->limit(500)->get();

        $suppliers = \App\Models\Supplier::when($since, function($q) use ($since) {
            return $q->where('updated_at', '>', $since);
        })->get();

        $purchases = \App\Models\Purchase::with(['supplier:id,name', 'items.product:id,name,sku'])
            ->when($since,
                fn($q) => $q->where('updated_at', '>', $since),
                fn($q) => $q->where('created_at', '>', now()->subDays(60))
            )
            ->latest()->limit(500)->get();

        $debts = \App\Models\CustomerDebt::with(['user:id,uuid,name,phone,email', 'payments'])
            ->when($since, function($q) use ($since) {
                return $q->where('updated_at', '>', $since);
            })->get();

        return response()->json([
            'categories' => $categories,
            'products' => $products,
            'users' => $users,
            'coupons' => $coupons,
            'settings' => $settings,
            'orders' => $orders,
            'suppliers' => $suppliers,
            'purchases' => $purchases,
            'debts' => $debts,
            // Полные списки живых id: сервер удаляет записи жёстко (без deleted_at),
            // по этим спискам клиент вычищает удалённое из локальной SQLite
            'alive_ids' => [
                'products' => Product::pluck('id'),
                'categories' => Category::pluck('id'),
                'coupons' => \App\Models\Coupon::pluck('id'),
            ],
            'server_time' => now()->toDateTimeString()
        ]);
    }

    /**
     * Push offline sales to cloud
     */
    public function push(Request $request)
    {
        $orders = $request->input('orders', []);
        $results = [];

        foreach ($orders as $orderData) {
            try {
                DB::beginTransaction();

                // Check for duplicate UUID
                if (Order::where('uuid', $orderData['uuid'])->exists()) {
                    $results[] = [
                        'uuid' => $orderData['uuid'],
                        'status' => 'success', // Already exists, consider synced
                        'message' => 'Duplicate skip'
                    ];
                    DB::rollBack();
                    continue;
                }

                // Map user_uuid to user_id
                $userId = null;
                if (!empty($orderData['user_uuid'])) {
                    $userId = \App\Models\User::where('uuid', $orderData['user_uuid'])->value('id');
                } elseif (!empty($orderData['user_id'])) {
                    $userId = $orderData['user_id'];
                }

                // Map staff_uuid to staff_id (кассир, пробивший продажу оффлайн)
                $staffId = null;
                if (!empty($orderData['staff_uuid'])) {
                    $staffId = \App\Models\User::where('uuid', $orderData['staff_uuid'])->value('id');
                } elseif (!empty($orderData['staff_id'])) {
                    $staffId = $orderData['staff_id'];
                }

                $orderTotal  = $orderData['total_amount'] - ($orderData['discount'] ?? 0);
                $cashAmount  = $orderData['cash_amount'] ?? ($orderData['payment_method'] === 'cash' ? $orderTotal : 0);
                $transferAmount = $orderData['transfer_amount'] ?? ($orderData['payment_method'] === 'transfer' ? $orderTotal : 0);

                // Локальная нумерация кассы может конфликтовать с сервером
                // (две кассы с одним префиксом, либо продажа до первого pull) —
                // при занятом номере назначаем следующий свободный по префиксу
                $orderNumber = $orderData['order_number'] ?? ('POS-OFF-'.Str::random(6));
                if (Order::where('order_number', $orderNumber)->exists()) {
                    $prefix = str_contains($orderNumber, '-')
                        ? substr($orderNumber, 0, strrpos($orderNumber, '-'))
                        : $orderNumber;
                    $maxNumber = (int) Order::where('order_number', 'like', "{$prefix}-%")
                        ->selectRaw("MAX(CAST(SUBSTRING(order_number FROM '[0-9]+$') AS INTEGER)) AS m")
                        ->value('m');
                    $orderNumber = $prefix . '-' . ($maxNumber + 1);
                }

                $order = Order::create([
                    'uuid' => $orderData['uuid'],
                    'order_number' => $orderNumber,
                    'user_id' => $userId,
                    'staff_id' => $staffId,
                    'total_amount' => $orderData['total_amount'],
                    'discount' => $orderData['discount'] ?? 0,
                    'payment_method' => $orderData['payment_method'],
                    'status' => 'delivered',
                    'payment_status' => ($orderData['is_debt'] ?? false) ? 'pending' : 'paid',
                    'created_at' => $orderData['created_at'],
                    'total' => $orderTotal, 
                    'subtotal' => $orderData['total_amount'],
                    'is_financed' => true,
                    'cash_received' => $cashAmount,
                    'transfer_received' => $transferAmount,
                    'notes' => 'Синхронизировано из POS (офлайн)',
                ]);

                foreach ($orderData['items'] as $item) {
                    // Резолв товара: uuid → числовой id → sku (локальный каталог кассы
                    // мог устареть, а order_items.product_id обязателен)
                    $product = \App\Models\Product::where('uuid', $item['product_uuid'] ?? '')->first();
                    if (!$product && is_numeric($item['product_uuid'] ?? null)) {
                        $product = \App\Models\Product::find($item['product_uuid']);
                    }
                    if (!$product && !empty($item['sku'])) {
                        $product = \App\Models\Product::where('sku', $item['sku'])->first();
                    }
                    if (!$product) {
                        throw new \Exception("Товар не найден на сервере: {$item['product_uuid']} / " . ($item['sku'] ?? 'без SKU'));
                    }

                    OrderItem::create([
                        'uuid' => $item['uuid'] ?? (string) Str::uuid(),
                        'order_id' => $order->id,
                        'product_id' => $product ? $product->id : null,
                        'quantity' => $item['quantity'],
                        'price' => $item['price'],
                        'total' => $item['quantity'] * $item['price'],
                        'product_name' => $item['name'] ?? ($product ? $product->name : 'Unknown'),
                        'product_sku' => $item['sku'] ?? ($product ? $product->sku : 'N/A'),
                        'purchase_price' => $product ? $product->purchase_price : 0,
                    ]);

                    if ($product) {
                        $product->decrement('stock_quantity', $item['quantity']);
                        $product->increment('sales_count', $item['quantity']);
                    }
                }

                // Create Financial Transactions
                if ($cashAmount > 0) {
                    FinancialTransaction::create([
                        'uuid' => (string) Str::uuid(),
                        'user_id' => $userId, // or staff_id from order
                        'type' => 'income',
                        'amount' => $cashAmount,
                        'category' => 'Продажа товаров (POS - Наличными)',
                        'description' => "Оплата заказа POS #{$order->order_number} (Офлайн)",
                        'trackable_type' => Order::class,
                        'trackable_id' => $order->id,
                        'payment_method' => 'cash',
                        'created_at' => $orderData['created_at'],
                    ]);
                }

                if ($transferAmount > 0) {
                    FinancialTransaction::create([
                        'uuid' => (string) Str::uuid(),
                        'user_id' => $userId,
                        'type' => 'income',
                        'amount' => $transferAmount,
                        'category' => 'Продажа товаров (POS - Перевод)',
                        'description' => "Оплата заказа POS #{$order->order_number} (Офлайн)",
                        'trackable_type' => Order::class,
                        'trackable_id' => $order->id,
                        'payment_method' => 'card',
                        'created_at' => $orderData['created_at'],
                    ]);
                }

                // Handle Debt if needed
                if (($orderData['is_debt'] ?? false) && $userId) {
                    $debtAmount = $orderTotal - ($cashAmount + $transferAmount);
                    if ($debtAmount > 0) {
                        \App\Models\CustomerDebt::create([
                            'uuid' => (string) Str::uuid(),
                            'user_id' => $userId,
                            'order_id' => $order->id,
                            'total_amount' => $orderTotal,
                            'paid_amount' => ($cashAmount + $transferAmount),
                            'remaining_amount' => $debtAmount,
                            'due_date' => $orderData['due_date'] ?? null,
                            'status' => 'active',
                            'created_at' => $orderData['created_at'],
                        ]);
                    }
                }

                DB::commit();
                // возвращаем номер: клиент обновит его локально, если сервер переназначил
                $results[] = ['uuid' => $orderData['uuid'], 'status' => 'success', 'order_number' => $orderNumber];

            } catch (\Exception $e) {
                DB::rollBack();
                Log::error("Sync push error for order {$orderData['uuid']}: " . $e->getMessage());
                $results[] = ['uuid' => $orderData['uuid'], 'status' => 'error', 'message' => $e->getMessage()];
            }
        }

        // ── Offline-операции (не продажи): товары, закупки, погашения долгов ──
        $operationResults = [];
        foreach ($request->input('operations', []) as $op) {
            $opUuid = $op['op_uuid'] ?? null;
            $type = $op['type'] ?? '';
            $payload = $op['payload'] ?? [];

            if (!$opUuid) {
                $operationResults[] = ['op_uuid' => null, 'status' => 'error', 'message' => 'op_uuid required'];
                continue;
            }

            // Идемпотентность: уже применённые операции пропускаем
            if (DB::table('sync_logs')->where('operation_uuid', $opUuid)->exists()) {
                $operationResults[] = ['op_uuid' => $opUuid, 'status' => 'success', 'message' => 'Duplicate skip'];
                continue;
            }

            try {
                DB::beginTransaction();
                $this->applyOperation($type, $payload);
                DB::table('sync_logs')->insert([
                    'operation_uuid' => $opUuid,
                    'type' => $type,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::commit();
                $operationResults[] = ['op_uuid' => $opUuid, 'status' => 'success'];
            } catch (\Exception $e) {
                DB::rollBack();
                Log::error("Sync operation error [{$type}] {$opUuid}: " . $e->getMessage());
                $operationResults[] = ['op_uuid' => $opUuid, 'status' => 'error', 'message' => $e->getMessage()];
            }
        }

        // Сообщаем остальным терминалам, что появились изменения
        $applied = collect($results)->concat($operationResults)->contains(fn($r) => ($r['status'] ?? '') === 'success');
        if ($applied) {
            try {
                broadcast(new \App\Events\PosSyncUpdated('push'));
            } catch (\Throwable $e) {
                // Soketi недоступен — не критично
            }
        }

        return response()->json(['results' => $results, 'operation_results' => $operationResults]);
    }

    private function applyOperation(string $type, array $payload): void
    {
        switch ($type) {
            case 'PRODUCT_CREATE':
                if (Product::where('uuid', $payload['uuid'] ?? '')->exists()) return;
                Product::create([
                    'uuid' => $payload['uuid'],
                    'name' => $payload['name'],
                    'sku' => $payload['sku'] ?? null,
                    'barcode' => $payload['barcode'] ?? null,
                    'price' => $payload['price'] ?? 0,
                    'sale_price' => $payload['sale_price'] ?? ($payload['price'] ?? 0),
                    'purchase_price' => $payload['purchase_price'] ?? 0,
                    'stock_quantity' => $payload['stock_quantity'] ?? 0,
                    'in_stock' => ($payload['stock_quantity'] ?? 0) > 0,
                    'is_active' => $payload['is_active'] ?? true,
                    'category_id' => $payload['category_id'] ?? null,
                ]);
                break;

            case 'PRODUCT_UPDATE':
                $product = $this->findProduct($payload);
                if (!$product) throw new \Exception('Товар не найден: ' . ($payload['uuid'] ?? $payload['server_id'] ?? '?'));
                $allowed = ['name', 'sku', 'barcode', 'price', 'sale_price', 'purchase_price', 'stock_quantity', 'is_active', 'is_hot', 'hot_order', 'hot_group'];
                $fields = array_intersect_key($payload['fields'] ?? [], array_flip($allowed));
                if (!empty($payload['category_id'])) $fields['category_id'] = $payload['category_id'];
                if (isset($fields['stock_quantity'])) $fields['in_stock'] = $fields['stock_quantity'] > 0;
                $product->update($fields);
                break;

            case 'PRODUCT_DELETE':
                $product = $this->findProduct($payload);
                // мягкое удаление: скрываем из продажи, чтобы не рвать связи заказов
                if ($product) $product->update(['is_active' => false]);
                break;

            case 'PURCHASE_CREATE':
                $totalAmount = 0;
                foreach ($payload['items'] as $item) {
                    $totalAmount += $item['quantity'] * $item['buy_price'];
                }
                $purchase = \App\Models\Purchase::create([
                    'supplier_id' => $payload['supplier_id'],
                    'total_amount' => $totalAmount,
                    'paid_amount' => $payload['paid_amount'] ?? 0,
                    'notes' => trim(($payload['notes'] ?? '') . ' [Синхронизировано из POS (офлайн)]'),
                    'created_at' => $payload['created_at'] ?? now(),
                ]);
                $unpaid = $totalAmount - ($payload['paid_amount'] ?? 0);
                if ($unpaid != 0) {
                    $purchase->supplier->increment('debt_to_supplier', $unpaid);
                }
                foreach ($payload['items'] as $item) {
                    \App\Models\PurchaseItem::create([
                        'purchase_id' => $purchase->id,
                        'product_id' => $item['product_id'],
                        'quantity' => $item['quantity'],
                        'buy_price' => $item['buy_price'],
                        'total' => $item['quantity'] * $item['buy_price'],
                    ]);
                    $product = Product::find($item['product_id']);
                    if ($product) {
                        $product->increment('stock_quantity', $item['quantity']);
                        $product->update(['in_stock' => true, 'purchase_price' => $item['buy_price']]);
                    }
                }
                break;

            case 'DEBT_PAY':
                $debt = \App\Models\CustomerDebt::findOrFail($payload['debt_id']);
                $payment = \App\Models\DebtPayment::create([
                    'customer_debt_id' => $debt->id,
                    'amount' => $payload['amount'],
                    'payment_method' => $payload['payment_method'] ?? 'cash',
                    'created_at' => $payload['created_at'] ?? now(),
                ]);
                $debt->increment('paid_amount', $payload['amount']);
                $debt->remaining_amount = $debt->total_amount - $debt->paid_amount;
                if ($debt->remaining_amount <= 0) {
                    $debt->status = 'paid';
                    $debt->remaining_amount = 0;
                    if ($debt->order_id) {
                        $debt->order()->update(['payment_status' => 'paid']);
                    }
                } else {
                    $debt->status = 'partial';
                }
                $debt->save();
                FinancialTransaction::create([
                    'user_id' => auth()->id(),
                    'type' => 'income',
                    'amount' => $payload['amount'],
                    'category' => 'debt_payment',
                    'trackable_type' => \App\Models\DebtPayment::class,
                    'trackable_id' => $payment->id,
                    'description' => "Оплата долга от пользователя #{$debt->user_id} (POS офлайн)",
                    'payment_method' => $payload['payment_method'] ?? 'cash',
                    'created_at' => $payload['created_at'] ?? now(),
                ]);
                break;

            default:
                throw new \Exception("Неизвестный тип операции: {$type}");
        }
    }

    private function findProduct(array $payload): ?Product
    {
        if (!empty($payload['uuid'])) {
            $product = Product::where('uuid', $payload['uuid'])->first();
            if ($product) return $product;
        }
        if (!empty($payload['server_id'])) {
            return Product::find($payload['server_id']);
        }
        return null;
    }

    private function getProductIdByUuid($uuid)
    {
        return Product::where('uuid', $uuid)->value('id');
    }
}
