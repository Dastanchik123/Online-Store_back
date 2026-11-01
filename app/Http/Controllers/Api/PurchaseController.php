<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePurchaseRequest;
use App\Http\Requests\UpdatePurchaseRequest;
use App\Models\FinancialTransaction;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Traits\ConvertsPackageQuantity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseController extends Controller
{
    use ConvertsPackageQuantity;

    public function index(Request $request)
    {
        $query = Purchase::with(['supplier:id,name', 'items.product:id,name,sku,price,sale_price,purchase_price,image,images,unit,package_unit,package_size']);

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

        return $query->latest()->paginate(min(max((int) $request->get('per_page', 15), 1), 200));
    }

    public function store(StorePurchaseRequest $request)
    {
        $validated = $request->validated();

        // Оплата сверх суммы закупки уводила долг поставщику в минус и
        // раздувала расходы в отчётах (заплачено 1000 за закупку на 50)
        $total = array_sum(array_map(fn ($i) => $i['quantity'] * $i['buy_price'], $validated['items']));
        if (round((float) $validated['paid_amount'] * 100) > round($total * 100)) {
            throw ValidationException::withMessages([
                'paid_amount' => ['Оплачено больше суммы закупки (' . round($total, 2) . ' сом).'],
            ]);
        }

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
                    $isPackage = (bool) ($itemData['is_package'] ?? false);

                    PurchaseItem::create([
                        'purchase_id' => $purchase->id,
                        'product_id'  => $itemData['product_id'],
                        'quantity'    => $itemData['quantity'],
                        'is_package'  => $isPackage,
                        'buy_price'   => $itemData['buy_price'],
                        'total'       => $itemData['quantity'] * $itemData['buy_price'],
                    ]);


                    $product = Product::find($itemData['product_id']);
                    if ($product) {
                        $product->increment('stock_quantity', $this->baseQuantity($product, $itemData['quantity'], $isPackage));
                        $product->update([
                            'in_stock' => true,
                            ...$this->latestCost($product, (float) $itemData['buy_price'], $isPackage),
                            ...(isset($itemData['sale_price']) ? ['price' => $itemData['sale_price']] : []),
                        ]);
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

    /**
     * Позиции закупки с полем "доступно для возврата" (quantity - returned_quantity) —
     * источник данных для формы "Новый возврат поставщику" на фронте.
     */
    public function returnableItems(Purchase $purchase)
    {
        $items = $purchase->items()
            ->with('product:id,name,sku,unit,package_unit,package_size')
            ->get()
            ->map(function (PurchaseItem $item) {
                return [
                    'id'                => $item->id,
                    'purchase_id'       => $item->purchase_id,
                    'product_id'        => $item->product_id,
                    'product'           => $item->product,
                    'quantity'          => (float) $item->quantity,
                    'returned_quantity' => (float) $item->returned_quantity,
                    'available'         => max(0, (float) $item->quantity - (float) $item->returned_quantity),
                    'is_package'        => (bool) $item->is_package,
                    'buy_price'         => (float) $item->buy_price,
                ];
            });

        return response()->json($items);
    }

    public function update(UpdatePurchaseRequest $request, Purchase $purchase)
    {
        $validated = $request->validated();

        // Позиции пересоздаются заново — ссылки возврата поставщику на старые
        // purchase_items оборвались бы, а остаток пересчитался бы без учёта возврата
        if (isset($validated['items']) && $this->hasActiveSupplierReturns($purchase)) {
            return response()->json([
                'message' => 'Нельзя менять позиции закупки: по ней есть возврат поставщику. Сначала отмените возврат.',
            ], 409);
        }

        // Как в destroy(): старые позиции списываются со склада целиком — если
        // товар уже продан, остаток молча уходил в минус. Считаем итоговое
        // изменение по каждому товару (новое количество минус старое)
        if (isset($validated['items'])) {
            $net = [];
            foreach ($purchase->items as $oldItem) {
                $product = Product::find($oldItem->product_id);
                if ($product) {
                    $net[$product->id] = ($net[$product->id] ?? 0) - $this->baseQuantity($product, (float) $oldItem->quantity, (bool) $oldItem->is_package);
                }
            }
            foreach ($validated['items'] as $itemData) {
                $product = Product::find($itemData['product_id']);
                if ($product) {
                    $net[$product->id] = ($net[$product->id] ?? 0) + $this->baseQuantity($product, (float) $itemData['quantity'], (bool) ($itemData['is_package'] ?? false));
                }
            }
            foreach ($net as $productId => $delta) {
                $product = Product::find($productId);
                $result  = (float) $product->stock_quantity + $delta;
                if ($delta < 0 && $result < -0.0005) {
                    return response()->json([
                        'message' => "Нельзя уменьшить закупку: товар «{$product->name}» уже продан, остаток ушёл бы в минус ({$result}).",
                    ], 409);
                }
            }
        }

        return DB::transaction(function () use ($validated, $purchase, $request) {
            $oldUnpaid   = $purchase->total_amount - $purchase->paid_amount;
            $oldSupplier = $purchase->supplier;

            
            if (isset($validated['items'])) {
                
                foreach ($purchase->items as $oldItem) {
                    $product = Product::find($oldItem->product_id);
                    if ($product) {
                        $product->decrement('stock_quantity', $this->baseQuantity($product, (float) $oldItem->quantity, (bool) $oldItem->is_package));
                    }
                }


                $purchase->items()->delete();


                $totalAmount = 0;
                foreach ($validated['items'] as $itemData) {
                    $isPackage    = (bool) ($itemData['is_package'] ?? false);
                    $subtotal     = $itemData['quantity'] * $itemData['buy_price'];
                    $totalAmount += $subtotal;

                    PurchaseItem::create([
                        'purchase_id' => $purchase->id,
                        'product_id'  => $itemData['product_id'],
                        'quantity'    => $itemData['quantity'],
                        'is_package'  => $isPackage,
                        'buy_price'   => $itemData['buy_price'],
                        'total'       => $subtotal,
                    ]);

                    $product = Product::find($itemData['product_id']);
                    if ($product) {
                        $product->increment('stock_quantity', $this->baseQuantity($product, $itemData['quantity'], $isPackage));
                        $product->update([
                            'in_stock' => true,
                            ...$this->latestCost($product, (float) $itemData['buy_price'], $isPackage),
                            ...(isset($itemData['sale_price']) ? ['price' => $itemData['sale_price']] : []),
                        ]);
                    }
                }
                $purchase->total_amount = $totalAmount;
            }

            
            $purchase->fill($request->only(['supplier_id', 'paid_amount', 'notes']));
            if (round((float) $purchase->paid_amount * 100) > round((float) $purchase->total_amount * 100)) {
                // исключение откатывает транзакцию вместе с изменением позиций
                throw ValidationException::withMessages([
                    'paid_amount' => ['Оплачено больше суммы закупки (' . round((float) $purchase->total_amount, 2) . ' сом).'],
                ]);
            }
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
        // Проверяем ДО каких-либо изменений: если товар из этой закупки уже
        // перепродан, реверс остатка при удалении закупки увёл бы его в минус
        // задним числом (без реального источника) — блокируем, а не молча портим склад.

        // Возврат поставщику уже списал часть этой закупки со склада: полный
        // откат количества увёл бы остаток ниже реального, а возврат остался
        // бы без закупки
        if ($this->hasActiveSupplierReturns($purchase)) {
            return response()->json([
                'message' => 'Нельзя удалить закупку: по ней есть возврат поставщику. Сначала отмените возврат.',
            ], 409);
        }

        foreach ($purchase->items as $item) {
            $product = Product::find($item->product_id);
            if (! $product) {
                continue;
            }

            $reverseQty  = $this->baseQuantity($product, (float) $item->quantity, (bool) $item->is_package);
            $resultStock = (float) $product->stock_quantity - $reverseQty;

            if ($resultStock < 0) {
                return response()->json([
                    'message' => "Нельзя удалить закупку: товар «{$product->name}» уже частично или полностью продан, откат остатка увёл бы его в минус ({$resultStock}).",
                ], 409);
            }
        }

        DB::transaction(function () use ($purchase) {

            $unpaid = $purchase->total_amount - $purchase->paid_amount;
            if ($unpaid != 0) {
                $purchase->supplier->decrement('debt_to_supplier', $unpaid);
            }


            foreach ($purchase->items as $item) {
                $product = Product::find($item->product_id);
                if ($product) {
                    $product->decrement('stock_quantity', $this->baseQuantity($product, (float) $item->quantity, (bool) $item->is_package));
                }
            }


            FinancialTransaction::where('trackable_type', Purchase::class)
                ->where('trackable_id', $purchase->id)
                ->delete();

            // Позиции закупки — исторические строки; при soft-delete родителя
            // DB-уровневый ON DELETE CASCADE не срабатывает, чистим явно (как в update()).
            $purchase->items()->delete();

            $purchase->delete();
        });

        return response()->json(['message' => 'Purchase deleted successfully']);
    }

    // Себестоимость товара — по последней закупке. Раньше закупка её не
    // меняла: касса проверяла «ниже себестоимости» по старой цене, а прибыль
    // в отчётах считалась от неё же. Упаковка (рулон/мешок) пересчитывается
    // в цену базовой единицы (метр/кг).
    private function latestCost(Product $product, float $buyPrice, bool $isPackage): array
    {
        if ($buyPrice <= 0) {
            return [];
        }

        if ($isPackage && (float) $product->package_size > 0) {
            return [
                'purchase_price'         => round($buyPrice / (float) $product->package_size, 2),
                'package_purchase_price' => round($buyPrice, 2),
            ];
        }

        return ['purchase_price' => round($buyPrice, 2)];
    }

    // Возврат поставщику ссылается на позиции закупки (supplier_return_items.purchase_item_id)
    private function hasActiveSupplierReturns(Purchase $purchase): bool
    {
        return \App\Models\SupplierReturnItem::whereIn('purchase_item_id', $purchase->items()->pluck('id'))
            ->whereHas('supplierReturn', fn ($q) => $q->where('status', '!=', 'cancelled'))
            ->exists();
    }

    public function registerPayment(Request $request, Purchase $purchase)
    {
        $data = $request->validate([
            'amount' => 'required|numeric|min:0.01',
        ]);

        return DB::transaction(function () use ($data, $purchase) {
            // Проверка под блокировкой: двойное нажатие «Оплатить» иначе
            // проходило проверку остатка дважды и переплачивало поставщику
            $purchase  = Purchase::whereKey($purchase->id)->lockForUpdate()->firstOrFail();
            $remaining = (float) $purchase->total_amount - (float) $purchase->paid_amount;
            if (round($data['amount'] * 100) > round($remaining * 100)) {
                throw ValidationException::withMessages([
                    'amount' => ['Сумма больше остатка долга по закупке (' . round(max(0, $remaining), 2) . ' сом).'],
                ]);
            }

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
