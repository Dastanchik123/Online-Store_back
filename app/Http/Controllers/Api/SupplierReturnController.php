<?php
namespace App\Http\Controllers\Api;

use App\Exceptions\SupplierReturnException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSupplierReturnRequest;
use App\Http\Requests\UpdateSupplierReturnRequest;
use App\Models\AuditLog;
use App\Models\InventoryAdjustment;
use App\Models\Product;
use App\Models\PurchaseItem;
use App\Models\Supplier;
use App\Models\SupplierReturn;
use App\Models\SupplierReturnItem;
use App\Traits\ConvertsPackageQuantity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SupplierReturnController extends Controller
{
    use ConvertsPackageQuantity;

    public function index(Request $request)
    {
        $query = SupplierReturn::with(['supplier:id,name', 'items.product:id,name,sku,unit']);

        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->supplier_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('return_date', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('return_date', '<=', $request->date_to);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('notes', 'like', "%{$search}%")
                    ->orWhere('reason', 'like', "%{$search}%");
            });
        }

        return $query->latest()->paginate($request->get('per_page', 15));
    }

    public function store(StoreSupplierReturnRequest $request)
    {
        $validated = $request->validated();

        try {
            return DB::transaction(function () use ($validated) {
                $supplierReturn = SupplierReturn::create([
                    'supplier_id'  => $validated['supplier_id'],
                    'user_id'      => auth()->id(),
                    'status'       => 'draft',
                    'return_date'  => $validated['return_date'] ?? now()->toDateString(),
                    'reason'       => $validated['reason'] ?? null,
                    'notes'        => $validated['notes'] ?? null,
                    'total_amount' => 0,
                ]);

                $totalAmount = $this->syncItems($supplierReturn, $validated['items'], $validated['supplier_id']);
                $supplierReturn->update(['total_amount' => $totalAmount]);

                AuditLog::record('supplier_return.created', $supplierReturn, null, $supplierReturn->fresh()->toArray());

                return response()->json($supplierReturn->load('supplier', 'items.product'), 201);
            });
        } catch (SupplierReturnException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }
    }

    public function show(SupplierReturn $supplierReturn)
    {
        return $supplierReturn->load(
            'supplier',
            'items.product',
            'items.purchaseItem.purchase',
            'creator:id,name',
            'confirmedBy:id,name',
            'cancelledBy:id,name'
        );
    }

    public function update(UpdateSupplierReturnRequest $request, SupplierReturn $supplierReturn)
    {
        if ($supplierReturn->status !== 'draft') {
            return response()->json(['message' => 'Можно редактировать только черновик возврата.'], 409);
        }

        $validated = $request->validated();

        try {
            return DB::transaction(function () use ($validated, $request, $supplierReturn) {
                $old = $supplierReturn->toArray();

                $supplierReturn->fill($request->only(['supplier_id', 'return_date', 'reason', 'notes']));
                $supplierId = $validated['supplier_id'] ?? $supplierReturn->supplier_id;

                if (isset($validated['items'])) {
                    $supplierReturn->total_amount = $this->syncItems($supplierReturn, $validated['items'], $supplierId);
                }

                $supplierReturn->save();

                AuditLog::record('supplier_return.updated', $supplierReturn, $old, $supplierReturn->fresh()->toArray());

                return response()->json($supplierReturn->load('supplier', 'items.product'));
            });
        } catch (SupplierReturnException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }
    }

    public function destroy(SupplierReturn $supplierReturn)
    {
        if ($supplierReturn->status !== 'draft') {
            return response()->json(['message' => 'Нельзя удалить проведённый возврат. Используйте отмену.'], 409);
        }

        DB::transaction(function () use ($supplierReturn) {
            $old = $supplierReturn->toArray();
            $supplierReturn->items()->delete();
            $supplierReturn->delete();
            AuditLog::record('supplier_return.deleted', $supplierReturn, $old, null);
        });

        return response()->json(['message' => 'Supplier return deleted successfully']);
    }

    public function confirm(SupplierReturn $supplierReturn)
    {
        try {
            return DB::transaction(function () use ($supplierReturn) {
                $return = SupplierReturn::whereKey($supplierReturn->id)->lockForUpdate()->firstOrFail();

                if ($return->status !== 'draft') {
                    throw new SupplierReturnException('Возврат уже обработан (текущий статус: ' . $return->status . ').', 409);
                }

                $items = $return->items()->with('purchaseItem.product')->get();
                if ($items->isEmpty()) {
                    throw new SupplierReturnException('В возврате нет позиций.', 422);
                }

                $totalAmount = 0;

                foreach ($items as $item) {
                    $purchaseItem = PurchaseItem::whereKey($item->purchase_item_id)->lockForUpdate()->first();
                    if (! $purchaseItem) {
                        throw new SupplierReturnException('Позиция закупки не найдена.', 422);
                    }

                    $available = (float) $purchaseItem->quantity - (float) $purchaseItem->returned_quantity;
                    $quantity  = (float) $item->quantity;

                    if ($quantity > $available + 0.0005) {
                        $name = $purchaseItem->product->name ?? "товар #{$purchaseItem->product_id}";
                        throw new SupplierReturnException(
                            "Нельзя вернуть больше доступного: «{$name}» — доступно {$available}, запрошено {$quantity}.",
                            422
                        );
                    }

                    $product = Product::whereKey($item->product_id)->lockForUpdate()->first();
                    if (! $product) {
                        throw new SupplierReturnException('Товар не найден.', 422);
                    }

                    $baseQty = $this->baseQuantity($product, $quantity, (bool) $purchaseItem->is_package);

                    if ($baseQty > (float) $product->stock_quantity + 0.0005) {
                        throw new SupplierReturnException(
                            "Недостаточно товара «{$product->name}» на складе: остаток {$product->stock_quantity}, требуется списать {$baseQty}.",
                            422
                        );
                    }

                    // Не доверяем снимку из черновика — пересчитываем от актуальной цены закупки
                    $unitCost = (float) $purchaseItem->buy_price;
                    $total    = round($quantity * $unitCost, 2);
                    if (abs($total - (float) $item->total) > 0.001 || abs($unitCost - (float) $item->unit_cost) > 0.001) {
                        $item->update(['unit_cost' => $unitCost, 'total' => $total]);
                    }

                    $oldStock = (float) $product->stock_quantity;
                    $newStock = $oldStock - $baseQty;
                    $product->update(['stock_quantity' => $newStock]);

                    InventoryAdjustment::create([
                        'product_id'     => $product->id,
                        'old_quantity'   => $oldStock,
                        'new_quantity'   => $newStock,
                        'difference'     => -$baseQty,
                        'reason'         => 'supplier_return',
                        'user_id'        => auth()->id(),
                        'reference_type' => SupplierReturn::class,
                        'reference_id'   => $return->id,
                    ]);

                    $purchaseItem->increment('returned_quantity', $quantity);

                    $totalAmount += $total;
                }

                $totalAmount = round($totalAmount, 2);

                $supplier = Supplier::whereKey($return->supplier_id)->lockForUpdate()->first();
                if ($supplier) {
                    $supplier->decrement('debt_to_supplier', $totalAmount);
                }

                $old = $return->toArray();

                $return->update([
                    'status'       => 'confirmed',
                    'total_amount' => $totalAmount,
                    'confirmed_at' => now(),
                    'confirmed_by' => auth()->id(),
                ]);

                AuditLog::record('supplier_return.confirmed', $return, $old, $return->fresh()->toArray());

                return response()->json($return->fresh()->load('supplier', 'items.product'));
            });
        } catch (SupplierReturnException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }
    }

    public function cancel(SupplierReturn $supplierReturn)
    {
        try {
            return DB::transaction(function () use ($supplierReturn) {
                $return = SupplierReturn::whereKey($supplierReturn->id)->lockForUpdate()->firstOrFail();

                if ($return->status !== 'confirmed') {
                    throw new SupplierReturnException(
                        'Отменить можно только проведённый возврат (текущий статус: ' . $return->status . ').',
                        409
                    );
                }

                $items = $return->items()->get();

                foreach ($items as $item) {
                    $purchaseItem = PurchaseItem::whereKey($item->purchase_item_id)->lockForUpdate()->first();
                    $product      = Product::whereKey($item->product_id)->lockForUpdate()->first();

                    if ($product) {
                        $baseQty = $purchaseItem
                            ? $this->baseQuantity($product, (float) $item->quantity, (bool) $purchaseItem->is_package)
                            : (float) $item->quantity;

                        $oldStock = (float) $product->stock_quantity;
                        $newStock = $oldStock + $baseQty;
                        $product->update(['stock_quantity' => $newStock]);

                        InventoryAdjustment::create([
                            'product_id'     => $product->id,
                            'old_quantity'   => $oldStock,
                            'new_quantity'   => $newStock,
                            'difference'     => $baseQty,
                            'reason'         => 'supplier_return_cancel',
                            'user_id'        => auth()->id(),
                            'reference_type' => SupplierReturn::class,
                            'reference_id'   => $return->id,
                        ]);
                    }

                    if ($purchaseItem) {
                        $purchaseItem->decrement('returned_quantity', (float) $item->quantity);
                    }
                }

                $supplier = Supplier::whereKey($return->supplier_id)->lockForUpdate()->first();
                if ($supplier) {
                    $supplier->increment('debt_to_supplier', $return->total_amount);
                }

                $old = $return->toArray();

                $return->update([
                    'status'       => 'cancelled',
                    'cancelled_at' => now(),
                    'cancelled_by' => auth()->id(),
                ]);

                AuditLog::record('supplier_return.cancelled', $return, $old, $return->fresh()->toArray());

                return response()->json($return->fresh()->load('supplier', 'items.product'));
            });
        } catch (SupplierReturnException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }
    }

    /**
     * Пересобирает позиции возврата (используется и в store, и в update — как
     * PurchaseController::update пересобирает purchase_items). Мягкая проверка
     * доступного количества здесь — для UX черновика; авторитетная проверка
     * (с блокировкой строк) выполняется в confirm().
     */
    private function syncItems(SupplierReturn $supplierReturn, array $items, int $supplierId): float
    {
        $supplierReturn->items()->delete();

        $totalAmount = 0;

        foreach ($items as $itemData) {
            $purchaseItem = PurchaseItem::with('product', 'purchase')->find($itemData['purchase_item_id']);

            if (! $purchaseItem || ! $purchaseItem->purchase || (int) $purchaseItem->purchase->supplier_id !== (int) $supplierId) {
                throw new SupplierReturnException('Позиция закупки не принадлежит выбранному поставщику.', 422);
            }

            $quantity  = (float) $itemData['quantity'];
            $available = (float) $purchaseItem->quantity - (float) $purchaseItem->returned_quantity;

            if ($quantity > $available + 0.0005) {
                $name = $purchaseItem->product->name ?? "товар #{$purchaseItem->product_id}";
                throw new SupplierReturnException(
                    "Нельзя вернуть больше доступного: «{$name}» — доступно {$available}, запрошено {$quantity}.",
                    422
                );
            }

            $unitCost = (float) $purchaseItem->buy_price;
            $total    = round($quantity * $unitCost, 2);

            SupplierReturnItem::create([
                'supplier_return_id' => $supplierReturn->id,
                'purchase_item_id'   => $purchaseItem->id,
                'product_id'         => $purchaseItem->product_id,
                'quantity'           => $quantity,
                'unit_cost'          => $unitCost,
                'total'              => $total,
            ]);

            $totalAmount += $total;
        }

        return round($totalAmount, 2);
    }
}
