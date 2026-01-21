<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FinancialTransaction;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PurchaseController extends Controller
{
    public function index(Request $request)
    {
        $query = Purchase::with(['supplier:id,name', 'items.product:id,name,sku']);

        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->supplier_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where('notes', 'like', "%{$search}%");
        }

        return $query->latest()->paginate($request->get('per_page', 15));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'supplier_id'        => 'required|exists:suppliers,id',
            'items'              => 'required|array',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity'   => 'required|integer|min:1',
            'items.*.buy_price'  => 'required|numeric|min:0',
            'paid_amount'        => 'required|numeric|min:0',
            'notes'              => 'nullable|string',
        ]);

        try {
            return DB::transaction(function () use ($validated) {
                $totalAmount = 0;
                foreach ($validated['items'] as $item) {
                    $totalAmount += $item['quantity'] * $item['buy_price'];
                }

                $purchase = Purchase::create([
                    'supplier_id'  => $validated['supplier_id'],
                    'total_amount' => $totalAmount,
                    'paid_amount'  => $validated['paid_amount'],
                    'notes'        => $validated['notes'] ?? null,
                ]);

                
                $unpaidAmount = $totalAmount - $validated['paid_amount'];
                if ($unpaidAmount != 0) {
                    $purchase->supplier->increment('debt_to_supplier', $unpaidAmount);
                }

                foreach ($validated['items'] as $itemData) {
                    PurchaseItem::create([
                        'purchase_id' => $purchase->id,
                        'product_id'  => $itemData['product_id'],
                        'quantity'    => $itemData['quantity'],
                        'buy_price'   => $itemData['buy_price'],
                        'total'       => $itemData['quantity'] * $itemData['buy_price'],
                    ]);

                    
                    $product = Product::find($itemData['product_id']);
                    if ($product) {
                        $product->increment('stock_quantity', $itemData['quantity']);
                        $product->update(['in_stock' => true]);
                    }
                }

                
                if ($validated['paid_amount'] > 0) {
                    FinancialTransaction::create([
                        'user_id'        => auth()->id(),
                        'type'           => 'expense',
                        'amount'         => $validated['paid_amount'],
                        'category'       => 'purchase',
                        'trackable_type' => Purchase::class,
                        'trackable_id'   => $purchase->id,
                        'description'    => "Оплата за покупку №{$purchase->id}",
                    ]);
                }

                return response()->json($purchase->load('items.product'), 201);
            });
        } catch (\Exception $e) {
            \Log::error("Purchase store error: " . $e->getMessage(), [
                'user_id' => auth()->id(),
                'payload' => $validated,
            ]);
            return response()->json([
                'message' => 'Ошибка при сохранении закупки: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function show(Purchase $purchase)
    {
        return $purchase->load('supplier', 'items.product');
    }

    public function update(Request $request, Purchase $purchase)
    {
        $validated = $request->validate([
            'supplier_id'        => 'sometimes|required|exists:suppliers,id',
            'paid_amount'        => 'sometimes|numeric|min:0',
            'notes'              => 'nullable|string',
            'items'              => 'sometimes|array',
            'items.*.product_id' => 'required_with:items|exists:products,id',
            'items.*.quantity'   => 'required_with:items|integer|min:1',
            'items.*.buy_price'  => 'required_with:items|numeric|min:0',
        ]);

        return DB::transaction(function () use ($validated, $purchase, $request) {
            $oldUnpaid   = $purchase->total_amount - $purchase->paid_amount;
            $oldSupplier = $purchase->supplier;

            
            if (isset($validated['items'])) {
                
                foreach ($purchase->items as $oldItem) {
                    $product = Product::find($oldItem->product_id);
                    if ($product) {
                        $product->decrement('stock_quantity', $oldItem->quantity);
                    }
                }

                
                $purchase->items()->delete();

                
                $totalAmount = 0;
                foreach ($validated['items'] as $itemData) {
                    $subtotal     = $itemData['quantity'] * $itemData['buy_price'];
                    $totalAmount += $subtotal;

                    PurchaseItem::create([
                        'purchase_id' => $purchase->id,
                        'product_id'  => $itemData['product_id'],
                        'quantity'    => $itemData['quantity'],
                        'buy_price'   => $itemData['buy_price'],
                        'total'       => $subtotal,
                    ]);

                    $product = Product::find($itemData['product_id']);
                    if ($product) {
                        $product->increment('stock_quantity', $itemData['quantity']);
                        $product->update(['in_stock' => true]);
                    }
                }
                $purchase->total_amount = $totalAmount;
            }

            
            $purchase->fill($request->only(['supplier_id', 'paid_amount', 'notes']));
            $purchase->save();

            
            $newUnpaid = $purchase->total_amount - $purchase->paid_amount;

            
            if ($oldSupplier->id != $purchase->supplier_id) {
                $oldSupplier->decrement('debt_to_supplier', $oldUnpaid);
                $purchase->supplier->increment('debt_to_supplier', $newUnpaid);
            } else {
                
                $diff = $newUnpaid - $oldUnpaid;
                if ($diff != 0) {
                    $purchase->supplier->increment('debt_to_supplier', $diff);
                }
            }

            
            
            if (isset($validated['paid_amount'])) {
                $targetAmount = $validated['paid_amount'];

                
                $transactions = FinancialTransaction::where('trackable_type', Purchase::class)
                    ->where('trackable_id', $purchase->id)
                    ->orderByDesc('created_at') 
                    ->get();

                $currentSum = $transactions->sum('amount');
                $diff       = $targetAmount - $currentSum;

                if (abs($diff) > 0.001) {
                    if ($diff > 0) {
                        
                        
                        $mainTransaction = $transactions->firstWhere('category', 'purchase');

                        if ($mainTransaction) {
                            $mainTransaction->increment('amount', $diff);
                        } else {
                            
                            FinancialTransaction::create([
                                'user_id'        => auth()->id(),
                                'type'           => 'expense',
                                'amount'         => $diff,
                                'category'       => 'purchase',
                                'trackable_type' => Purchase::class,
                                'trackable_id'   => $purchase->id,
                                'description'    => "Оплата за покупку №{$purchase->id}",
                                'created_at' => $purchase->created_at, 
                            ]);
                        }
                    } else {
                        
                        
                        $toRemove = abs($diff);

                        foreach ($transactions as $transaction) {
                            if ($toRemove <= 0) {
                                break;
                            }

                            if ($transaction->amount <= $toRemove) {
                                
                                $deduct  = $transaction->amount;
                                $transaction->delete();
                                $toRemove -= $deduct;
                            } else {
                                
                                $transaction->decrement('amount', $toRemove);
                                $toRemove = 0;
                            }
                        }
                    }
                }
            }

            return response()->json($purchase->load('supplier', 'items.product'));
        });
    }

    public function destroy(Purchase $purchase)
    {
        DB::transaction(function () use ($purchase) {
            
            $unpaid = $purchase->total_amount - $purchase->paid_amount;
            if ($unpaid != 0) {
                $purchase->supplier->decrement('debt_to_supplier', $unpaid);
            }

            
            foreach ($purchase->items as $item) {
                $product = Product::find($item->product_id);
                if ($product) {
                    $product->decrement('stock_quantity', $item->quantity);
                }
            }

            
            FinancialTransaction::where('trackable_type', Purchase::class)
                ->where('trackable_id', $purchase->id)
                ->delete();

            $purchase->delete();
        });

        return response()->json(['message' => 'Purchase deleted successfully']);
    }

    public function registerPayment(Request $request, Purchase $purchase)
    {
        $data = $request->validate([
            'amount' => 'required|numeric|min:0.01',
        ]);

        return DB::transaction(function () use ($data, $purchase) {
            $purchase->increment('paid_amount', $data['amount']);

            
            $purchase->supplier->decrement('debt_to_supplier', $data['amount']);

            
            FinancialTransaction::create([
                'user_id'        => auth()->id(),
                'type'           => 'expense',
                'amount'         => $data['amount'],
                'category'       => 'purchase_payment',
                'trackable_type' => Purchase::class,
                'trackable_id'   => $purchase->id,
                'description'    => "Доплата по закупке №{$purchase->id}",
            ]);

            return response()->json([
                'message'  => 'Payment registered',
                'purchase' => $purchase->load('supplier', 'items.product'),
            ]);
        });
    }
}
