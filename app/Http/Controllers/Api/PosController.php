<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomerDebt;
use App\Models\FinancialTransaction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PosController extends Controller
{
    public function summary(Request $request)
    {
        $today   = now()->startOfDay();
        $staffId = $request->get('staff_id');

        $cashQuery = FinancialTransaction::where('type', 'income')
            ->whereDate('created_at', $today)
            ->where('category', 'like', '%POS - Наличными%');

        $transferQuery = FinancialTransaction::where('type', 'income')
            ->whereDate('created_at', $today)
            ->where('category', 'like', '%POS - Перевод%');

        if ($staffId) {
            $cashQuery->whereHasMorph('trackable', [Order::class], function ($q) use ($staffId) {
                $q->where('staff_id', $staffId);
            });
            $transferQuery->whereHasMorph('trackable', [Order::class], function ($q) use ($staffId) {
                $q->where('staff_id', $staffId);
            });
        }

        $cashConfirmed     = $cashQuery->sum('amount');
        $transferConfirmed = $transferQuery->sum('amount');

        $pendingCashQuery = Order::whereDate('created_at', $today)
            ->where('notes', 'like', '%POS%')
            ->where('is_financed', false);

        $pendingTransferQuery = Order::whereDate('created_at', $today)
            ->where('notes', 'like', '%POS%')
            ->where('is_financed', false);

        $salesCountQuery = Order::whereDate('created_at', $today)
            ->where('notes', 'like', '%POS%');

        if ($staffId) {
            $pendingCashQuery->where('staff_id', $staffId);
            $pendingTransferQuery->where('staff_id', $staffId);
            $salesCountQuery->where('staff_id', $staffId);
        }

        $pendingCash     = $pendingCashQuery->sum('cash_received');
        $pendingTransfer = $pendingTransferQuery->sum('transfer_received');
        $salesCount      = $salesCountQuery->count();

        return response()->json([
            'cash_total'       => (float) $cashConfirmed,
            'transfer_total'   => (float) $transferConfirmed,
            'total'            => (float) ($cashConfirmed + $transferConfirmed),
            'pending_cash'     => (float) $pendingCash,
            'pending_transfer' => (float) $pendingTransfer,
            'sales_count'      => $salesCount,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'items'              => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity'   => 'required|integer|min:1',
            'items.*.price'      => 'required|numeric|min:0',
            'user_id'            => 'nullable|exists:users,id',
            'cash_amount'        => 'required|numeric|min:0',
            'transfer_amount'    => 'required|numeric|min:0',
            'is_debt'            => 'boolean',
            'due_date'           => 'nullable|date|after:today',
            'discount'           => 'nullable|numeric|min:0',
        ]);

        return DB::transaction(function () use ($request) {
            $items       = $request->items;
            $totalPaid   = $request->cash_amount + $request->transfer_amount;
            $totalAmount = 0;
            foreach ($items as $item) {
                $totalAmount += $item['price'] * $item['quantity'];
            }

            $allowPriceChange = \App\Models\Setting::where('key', 'pos_allow_price_change')->value('value');
            foreach ($items as $itemData) {
                $product = \App\Models\Product::find($itemData['product_id']);
                if ($product) {
                    $originalPrice = $product->sale_price ?? $product->price;
                    $sellingPrice  = (float) $itemData['price'];
                    $costPrice     = (float) ($product->purchase_price ?? 0);

                    if (($allowPriceChange === '0' || $allowPriceChange === 'false') && abs($sellingPrice - $originalPrice) > 0.01) {
                        throw new \Exception("Изменение цены запрещено настройками системы для товара: {$product->name}");
                    }

                    if ($sellingPrice < $costPrice) {
                        throw new \Exception("Цена товара '{$product->name}' ({$sellingPrice} сом) ниже себестоимости ({$costPrice} сом). Продажа невозможна.");
                    }
                }
            }

            $lastPosOrder = Order::where('notes', 'like', '%POS%')
                ->where('order_number', 'REGEXP', '^[0-9]+$')
                ->orderByRaw('CAST(order_number AS UNSIGNED) DESC')
                ->first();

            $nextNumber = $lastPosOrder ? (int) $lastPosOrder->order_number + 1 : 1;

            $orderTotal  = $totalAmount - ($request->discount ?? 0);
            $rawCash     = (float) $request->cash_amount;
            $rawTransfer = (float) $request->transfer_amount;

            $storeTransfer = min($rawTransfer, $orderTotal);
            $storeCash     = min($rawCash, max(0, $orderTotal - $storeTransfer));

            $order = Order::create([
                'order_number'      => (string) $nextNumber,
                'user_id'           => $request->user_id,
                'staff_id'          => auth()->id(),
                'subtotal'          => $totalAmount,
                'discount'          => $request->discount ?? 0,
                'total'             => $orderTotal,
                'status'            => 'delivered',
                'payment_status'    => ($request->is_debt && $totalPaid < $orderTotal) ? 'pending' : 'paid',
                'payment_method'    => $rawTransfer > 0 ? ($rawCash > 0 ? 'mixed' : 'card') : 'cash',
                'currency'          => 'SOM',
                'notes'             => 'Оффлайн продажа (POS)',
                'is_financed'       => true,
                'cash_received'     => $storeCash,
                'transfer_received' => $storeTransfer,
            ]);

            foreach ($items as $itemData) {
                $product = Product::find($itemData['product_id']);

                OrderItem::create([
                    'order_id'       => $order->id,
                    'product_id'     => $product->id,
                    'product_name'   => $product->name,
                    'product_sku'    => $product->sku,
                    'purchase_price' => $product->purchase_price,
                    'quantity'       => $itemData['quantity'],
                    'price'          => $itemData['price'],
                    'total'          => $itemData['price'] * $itemData['quantity'],
                ]);

                $product->decrement('stock_quantity', $itemData['quantity']);
                $product->increment('sales_count', $itemData['quantity']);
            }

            $orderTotal       = $order->total;
            $cashReceived     = (float) $request->cash_amount;
            $transferReceived = (float) $request->transfer_amount;

            $transferIncome = min($transferReceived, $orderTotal);
            $cashIncome     = min($cashReceived, max(0, $orderTotal - $transferIncome));

            if ($cashIncome > 0) {
                FinancialTransaction::create([
                    'user_id'     => auth()->id(),
                    'type'        => 'income',
                    'amount'      => $cashIncome,
                    'category'    => 'Продажа товаров (POS - Наличными)',
                    'description' => "Оплата заказа POS #{$order->order_number} (Наличные)",
                    'trackable_type' => Order::class,
                    'trackable_id'   => $order->id,
                    'payment_method' => 'cash',
                ]);
            }

            if ($transferIncome > 0) {
                FinancialTransaction::create([
                    'user_id'     => auth()->id(),
                    'type'        => 'income',
                    'amount'      => $transferIncome,
                    'category'    => 'Продажа товаров (POS - Перевод)',
                    'description' => "Оплата заказа POS #{$order->order_number} (Перевод)",
                    'trackable_type' => Order::class,
                    'trackable_id'   => $order->id,
                    'payment_method' => 'card',
                ]);
            }

            if ($request->is_debt && $request->user_id) {

                $allowDebt = \App\Models\Setting::where('key', 'pos_allow_debt')->value('value');
                if ($allowDebt === 'false' || $allowDebt === '0') {
                    throw new \Exception("Продажа в долг запрещена настройками системы");
                }

                $debtAmount = $totalAmount - $totalPaid;
                if ($debtAmount > 0) {
                    CustomerDebt::create([
                        'user_id'          => $request->user_id,
                        'order_id'         => $order->id,
                        'total_amount'     => $totalAmount,
                        'paid_amount'      => $totalPaid,
                        'remaining_amount' => $debtAmount,
                        'due_date'         => $request->due_date,
                        'status'           => 'active',
                    ]);
                }
            }

            return response()->json([
                'message'  => 'Продажа успешно оформлена',
                'order_id' => $order->id,
            ], 201);
        });
    }

    public function confirmFinance($id)
    {
        $order = Order::findOrFail($id);

        if ($order->is_financed) {
            return response()->json(['message' => 'Финансы уже подтверждены'], 400);
        }

        return DB::transaction(function () use ($order) {
            $orderTotal       = $order->total;
            $cashReceived     = (float) $order->cash_received;
            $transferReceived = (float) $order->transfer_received;

            $transferIncome = min($transferReceived, $orderTotal);
            $cashIncome     = min($cashReceived, max(0, $orderTotal - $transferIncome));

            if ($cashIncome > 0) {
                FinancialTransaction::create([
                    'user_id'     => auth()->id(),
                    'type'        => 'income',
                    'amount'      => $cashIncome,
                    'category'    => 'Продажа товаров (POS - Наличными)',
                    'description' => "Оплата заказа POS #{$order->id} (Наличные) - Подтверждено",
                    'trackable_type' => Order::class,
                    'trackable_id'   => $order->id,
                    'payment_method' => 'cash',
                ]);
            }

            if ($transferIncome > 0) {
                FinancialTransaction::create([
                    'user_id'     => auth()->id(),
                    'type'        => 'income',
                    'amount'      => $transferIncome,
                    'category'    => 'Продажа товаров (POS - Перевод)',
                    'description' => "Оплата заказа POS #{$order->id} (Перевод) - Подтверждено",
                    'trackable_type' => Order::class,
                    'trackable_id'   => $order->id,
                    'payment_method' => 'card',
                ]);
            }

            $order->update(['is_financed' => true]);

            return response()->json(['message' => 'Данные успешно внесены в финансовый отчет']);
        });
    }

    public function searchProducts(Request $request)
    {
        $search = $request->get('q');
        if (! $search) {
            return response()->json([]);
        }

        $products = Product::where('is_active', true)
            ->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%");
            })
            ->limit(10)
            ->get();

        return response()->json($products);
    }

    public function getAllProducts()
    {
        $products = Product::where('is_active', true)
            ->get(['id', 'name', 'sku', 'sale_price', 'price', 'stock_quantity']);

        return response()->json($products);
    }

    public function getStaff()
    {
        $staff = \App\Models\User::whereIn('role', ['admin', 'purchaser', 'cashier'])->get(['id', 'name', 'role']);
        return response()->json($staff);
    }
}
