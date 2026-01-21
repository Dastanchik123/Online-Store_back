<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Address;
use App\Models\Cart;
use App\Models\CustomerDebt;
use App\Models\FinancialTransaction;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    
    public function index(Request $request)
    {
        $user  = Auth::user();
        $query = Order::query()->with('items.product', 'shippingAddress', 'billingAddress', 'user', 'staff');

        
        
        if ($user && in_array($user->role, ['admin', 'purchaser', 'cashier'])) {
            if ($request->filled('user_id')) {
                $query->where('user_id', $request->user_id);
            }
            if ($request->filled('staff_id')) {
                $query->where('staff_id', $request->staff_id);
            }
        } else {
            
            $query->where('user_id', $user->id ?? null);
        }

        if ($request->filled('source')) {
            if ($request->source === 'pos') {
                $query->where('notes', 'like', '%POS%');
            } elseif ($request->source === 'online') {
                $query->where(function ($q) {
                    $q->whereNull('notes')
                        ->orWhere('notes', 'not like', '%POS%');
                });
            }
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

        if ($request->filled('date_from')) {
            
            
            $query->where('created_at', '>=', \Carbon\Carbon::parse($request->date_from)->subHours(12));
        }

        if ($request->filled('date_to')) {
            
            $query->where('created_at', '<=', \Carbon\Carbon::parse($request->date_to)->endOfDay()->subHours(6));
        }

        if ($request->filled('min_total')) {
            $query->where('total', '>=', $request->min_total);
        }

        if ($request->filled('max_total')) {
            $query->where('total', '<=', $request->max_total);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('id', 'like', "%{$search}%")
                    ->orWhere('order_number', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($qu) use ($search) {
                        $qu->where('name', 'like', "%{$search}%");
                    });
            });
        }

        $perPage = $request->get('per_page', 15);
        $orders  = $query->latest()->paginate($perPage);

        return response()->json($orders);
    }

    
    public function store(Request $request)
    {
        $validated = $request->validate([
            'shipping_address_id' => 'nullable|exists:addresses,id',
            'billing_address_id'  => 'nullable|exists:addresses,id',
            'shipping_address'    => 'nullable|array',
            'billing_address'     => 'nullable|array',
            'notes'               => 'nullable|string',
            'is_debt'             => 'boolean',
            'due_date'            => 'nullable|date|after:today',
            'initial_payment'     => 'nullable|numeric|min:0',
            'payment_method'      => 'nullable|string',
        ]);

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

            
            $subtotal     = $cart->total;
            $tax          = 0; 
            $shippingCost = 0; 
            $discount     = 0;
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
                'total'               => $total,
                'notes'               => $validated['notes'] ?? null,
            ]);

            
            foreach ($cart->items as $cartItem) {
                $product = $cartItem->product;

                OrderItem::create([
                    'order_id'       => $order->id,
                    'product_id'     => $product->id,
                    'product_name'   => $product->name,
                    'product_sku'    => $product->sku,
                    'quantity'       => $cartItem->quantity,
                    'purchase_price' => $product->purchase_price,
                    'price'          => $cartItem->price,
                    'total'          => $cartItem->subtotal,
                ]);

                
                $product->decrement('stock_quantity', $cartItem->quantity);
                $product->increment('sales_count', $cartItem->quantity);

                if ($product->stock_quantity <= 0) {
                    $product->update(['in_stock' => false]);
                }
            }

            
            $cart->items()->delete();

            
            if ($request->boolean('is_debt') && $user) {
                $initialPayment = $validated['initial_payment'] ?? 0;
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

            $order->load('items.product', 'shippingAddress', 'billingAddress');

            return response()->json($order, 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Order creation failed: ' . $e->getMessage()], 500);
        }
    }

    
    public function show(Order $order)
    {
        $user = Auth::user();

        if ($user && ! in_array($user->role, ['admin', 'purchaser', 'cashier']) && $order->user_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $order->load('items.product', 'shippingAddress', 'billingAddress', 'payments');
        return response()->json($order);
    }

    
    public function update(Request $request, Order $order)
    {
        $validated = $request->validate([
            'status'         => 'sometimes|in:pending,processing,shipped,delivered,cancelled,refunded',
            'payment_status' => 'sometimes|in:pending,paid,failed,refunded',
            'payment_method' => 'nullable|string',
            'notes'          => 'nullable|string',
        ]);

        $oldStatus        = $order->status;
        $oldPaymentStatus = $order->payment_status;
        $newStatus        = $validated['status'] ?? $oldStatus;
        $newPaymentStatus = $validated['payment_status'] ?? $oldPaymentStatus;

        return DB::transaction(function () use ($validated, $order, $oldStatus, $newStatus, $oldPaymentStatus, $newPaymentStatus) {
            $order->update($validated);

            

            
            if ($newPaymentStatus === 'paid') {
                CustomerDebt::where('order_id', $order->id)
                    ->where('status', '!=', 'paid')
                    ->update([
                        'status'           => 'paid',
                        'remaining_amount' => 0,
                        'paid_amount'      => DB::raw('total_amount'),
                    ]);
            }

            
            if (in_array($newStatus, ['cancelled', 'refunded'])) {
                CustomerDebt::where('order_id', $order->id)->update(['status' => 'cancelled']);
            }

            
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

            
            if ($newPaymentStatus === 'pending' && $oldPaymentStatus === 'paid') {
                $debt = CustomerDebt::where('order_id', $order->id)->first();
                if ($debt) {
                    
                    $debt->update([
                        'status'           => ($debt->paid_amount > 0) ? 'partial' : 'active',
                        'remaining_amount' => $debt->total_amount - $debt->paid_amount,
                    ]);
                } elseif ($order->user_id) {
                    
                    CustomerDebt::create([
                        'user_id'          => $order->user_id,
                        'order_id'         => $order->id,
                        'total_amount'     => $order->total,
                        'paid_amount'      => 0,
                        'remaining_amount' => $order->total,
                        'status'           => 'active',
                    ]);
                }

                
                FinancialTransaction::where('trackable_type', Order::class)
                    ->where('trackable_id', $order->id)
                    ->where('type', 'income')
                    ->delete();
            }

            
            if ($newPaymentStatus === 'paid' && $oldPaymentStatus !== 'paid') {
                
                $hasDebtPayment = FinancialTransaction::where('trackable_type', 'App\Models\DebtPayment')
                    ->whereHas('trackable', function ($q) use ($order) {
                        $q->where('order_id', $order->id);
                    })->exists();

                if (! $hasDebtPayment) {
                    FinancialTransaction::updateOrCreate(
                        ['trackable_type' => Order::class, 'trackable_id' => $order->id, 'type' => 'income'],
                        [
                            'amount'      => $order->total,
                            'category'    => 'sale',
                            'description' => "Оплата заказа #{$order->id} (смена статуса)",
                            'payment_method' => $order->payment_method ?? 'cash',
                        ]
                    );
                }
            }

            
            if ($newStatus === 'shipped' && $oldStatus !== 'shipped') {
                $order->update(['shipped_at' => now()]);
            }
            if ($newStatus === 'delivered' && $oldStatus !== 'delivered') {
                $order->update(['delivered_at' => now()]);
            }

            

            
            
            $activeStatuses = ['pending', 'processing', 'shipped', 'delivered'];
            $cancelStatuses = ['cancelled', 'refunded'];

            if (in_array($newStatus, $cancelStatuses) && in_array($oldStatus, $activeStatuses)) {
                foreach ($order->items as $item) {
                    $product = $item->product;

                    
                    $qtyToReturn = $item->quantity - $item->refunded_quantity;

                    if ($product && $qtyToReturn > 0) {
                        $product->increment('stock_quantity', $qtyToReturn);
                        $product->update(['in_stock' => true]);
                    }

                    
                    $item->update(['refunded_quantity' => $item->quantity]);
                }
            }

            
            

            
            
            

            
            
            

            

            

            if (in_array($newStatus, $cancelStatuses) && in_array($oldStatus, $activeStatuses)) {
                foreach ($order->items as $item) {
                    $product = $item->product;

                    
                    $qtyToReturn = max(0, $item->quantity - $item->refunded_quantity);

                    if ($product && $qtyToReturn > 0) {
                        $product->increment('stock_quantity', $qtyToReturn);
                        $product->update(['in_stock' => true]);
                    }

                    
                    $item->update(['refunded_quantity' => $item->quantity]);
                }
            }

            
            if (in_array($oldStatus, $cancelStatuses) && in_array($newStatus, $activeStatuses)) {
                foreach ($order->items as $item) {
                    $product = $item->product;
                    if ($product) {
                        
                        
                        $product->decrement('stock_quantity', $item->quantity);
                        if ($product->stock_quantity <= 0) {
                            $product->update(['in_stock' => false]);
                        }
                    }
                    
                    $item->update(['refunded_quantity' => 0]);
                }

                
                FinancialTransaction::where('trackable_type', Order::class)
                    ->where('trackable_id', $order->id)
                    ->where('category', 'refund')
                    ->delete();

                
                CustomerDebt::where('order_id', $order->id)
                    ->where('status', 'cancelled')
                    ->update([
                        'status' => DB::raw("CASE WHEN remaining_amount > 0 THEN 'active' ELSE 'paid' END"),
                    ]);
            }

            
            if ($newStatus === 'refunded' && $oldStatus !== 'refunded') {
                
                $totalIncome = FinancialTransaction::where('trackable_type', Order::class)
                    ->where('trackable_id', $order->id)
                    ->where('type', 'income')
                    ->sum('amount');

                
                $alreadyRefunded = FinancialTransaction::where('trackable_type', Order::class)
                    ->where('trackable_id', $order->id)
                    ->where('category', 'refund')
                    ->sum('amount');

                
                $amountToRefund = $totalIncome - $alreadyRefunded;

                if ($amountToRefund > 0) {
                    FinancialTransaction::create([
                        'type'           => 'expense',
                        'amount'         => $amountToRefund,
                        'category'       => 'refund',
                        'trackable_type' => Order::class,
                        'trackable_id'   => $order->id,
                        'description'    => "Полный возврат средств за заказ #{$order->id} (остаток)",
                        'created_at' => now(),
                    ]);
                }

                
                CustomerDebt::where('order_id', $order->id)
                    ->where('status', '!=', 'paid')
                    ->update([
                        'status'           => 'cancelled',
                        'remaining_amount' => 0,
                    ]);
            }

            
            
            
            if ($newStatus === 'cancelled' && $oldStatus !== 'cancelled') {
                CustomerDebt::where('order_id', $order->id)
                    ->where('status', '!=', 'paid')
                    ->update([
                        'status'           => 'cancelled',
                        'remaining_amount' => 0,
                    ]);
            }

            return response()->json($order->load('items.product'));
        });
    }

    
    public function cancel(Order $order)
    {
        $user = Auth::user();

        if ($user && $order->user_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if (! in_array($order->status, ['pending', 'processing'])) {
            return response()->json(['message' => 'Order cannot be cancelled'], 400);
        }

        DB::beginTransaction();
        try {
            
            foreach ($order->items as $item) {
                $product = $item->product;
                $product->increment('stock_quantity', $item->quantity);
                $product->update(['in_stock' => true]);
            }

            $order->update(['status' => 'cancelled']);
            DB::commit();

            return response()->json(['message' => 'Order cancelled successfully']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to cancel order'], 500);
        }
    }

    
    public function returnItems(Request $request, Order $order)
    {
        $validated = $request->validate([
            'items'            => 'required|array',
            'items.*.id'       => 'required|exists:order_items,id',
            'items.*.quantity' => 'required|integer|min:1',
        ]);

        return DB::transaction(function () use ($validated, $order) {
            $totalRefundAmount = 0;

            foreach ($validated['items'] as $returnItem) {
                $orderItem = OrderItem::where('order_id', $order->id)
                    ->where('id', $returnItem['id'])
                    ->firstOrFail();

                
                $availableToReturn = $orderItem->quantity - $orderItem->refunded_quantity;

                if ($returnItem['quantity'] > $availableToReturn) {
                    throw new \Exception("Нельзя вернуть {$returnItem['quantity']} шт. товара '{$orderItem->product_name}'. Доступно для возврата: {$availableToReturn}.");
                }

                
                $orderItem->increment('refunded_quantity', $returnItem['quantity']);

                
                
                $product = $orderItem->product;
                if ($product) {
                    $product->increment('stock_quantity', $returnItem['quantity']);
                    $product->update(['in_stock' => true]);
                }

                
                
                $totalRefundAmount += $returnItem['quantity'] * $orderItem->price;
            }

            
            if ($totalRefundAmount > 0) {
                FinancialTransaction::create([
                    'user_id'        => auth()->id(),
                    'type'           => 'expense',
                    'amount'         => $totalRefundAmount,
                    'category'       => 'refund',
                    'trackable_type' => Order::class,
                    'trackable_id'   => $order->id,
                    'description'    => "Частичный возврат товаров по заказу #{$order->id}",
                    'created_at' => now(),
                ]);
            }

            
            
            $debt = CustomerDebt::where('order_id', $order->id)->first();
            if ($debt && $debt->status !== 'cancelled') {
                $newRemaining           = max(0, $debt->remaining_amount - $totalRefundAmount);
                $debt->remaining_amount = $newRemaining;

                
                $debt->total_amount = max(0, $debt->total_amount - $totalRefundAmount);

                if ($newRemaining == 0) {
                    $debt->status = 'paid';
                }
                $debt->save();
            }

            return response()->json([
                'message' => 'Items returned successfully',
                'order'   => $order->refresh()->load('items.product'),
            ]);
        });
    }

    
    public function trackByNumber($orderNumber)
    {
        $query = Order::with(['items.product', 'shippingAddress', 'user']);

        if (is_numeric($orderNumber)) {
            $query->where(function ($q) use ($orderNumber) {
                $q->where('order_number', $orderNumber)
                    ->orWhere('id', $orderNumber);
            });
        } else {
            $query->where('order_number', $orderNumber);
        }

        $order = $query->first();

        if (! $order) {
            return response()->json(['message' => 'Order not found'], 404);
        }

        return response()->json($order);
    }
}
