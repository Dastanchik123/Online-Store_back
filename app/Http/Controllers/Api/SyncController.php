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

        return response()->json([
            'categories' => $categories,
            'products' => $products,
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

                $orderTotal  = $orderData['total_amount'] - ($orderData['discount'] ?? 0);
                $cashAmount  = $orderData['cash_amount'] ?? ($orderData['payment_method'] === 'cash' ? $orderTotal : 0);
                $transferAmount = $orderData['transfer_amount'] ?? ($orderData['payment_method'] === 'transfer' ? $orderTotal : 0);

                $order = Order::create([
                    'uuid' => $orderData['uuid'],
                    'order_number' => $orderData['order_number'] ?? ('POS-OFF-'.Str::random(6)),
                    'user_id' => $userId,
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
                    $product = \App\Models\Product::where('uuid', $item['product_uuid'])->first();
                    
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
                $results[] = ['uuid' => $orderData['uuid'], 'status' => 'success'];

            } catch (\Exception $e) {
                DB::rollBack();
                Log::error("Sync push error for order {$orderData['uuid']}: " . $e->getMessage());
                $results[] = ['uuid' => $orderData['uuid'], 'status' => 'error', 'message' => $e->getMessage()];
            }
        }

        return response()->json(['results' => $results]);
    }

    private function getProductIdByUuid($uuid)
    {
        return Product::where('uuid', $uuid)->value('id');
    }
}
