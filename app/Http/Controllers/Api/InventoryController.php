<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryAdjustment;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventoryController extends Controller
{
    public function index(Request $request)
    {
        $query = InventoryAdjustment::with('product', 'user');

        if ($request->filled('product_id')) {
            $query->where('product_id', $request->product_id);
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->filled('reason')) {
            $query->where('reason', 'like', "%{$request->reason}%");
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->whereHas('product', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%");
            });
        }

        return $query->latest()->paginate($request->get('per_page', 15));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'product_id'   => 'required|exists:products,id',
            'new_quantity' => 'required|integer|min:0',
            'reason'       => 'required|string',
        ]);

        return DB::transaction(function () use ($validated, $request) {
            $product     = Product::findOrFail($validated['product_id']);
            $oldQuantity = $product->stock_quantity;
            $difference  = $validated['new_quantity'] - $oldQuantity;

            $adjustment = InventoryAdjustment::create([
                'product_id'   => $product->id,
                'old_quantity' => $oldQuantity,
                'new_quantity' => $validated['new_quantity'],
                'difference'   => $difference,
                'reason'       => $validated['reason'],
                'user_id'      => $request->user()->id,
            ]);

            
            $product->update([
                'stock_quantity' => $validated['new_quantity'],
                'in_stock'       => $validated['new_quantity'] > 0,
            ]);

            return response()->json($adjustment->load('product'), 201);
        });
    }

    
    public function show(InventoryAdjustment $adjustment)
    {
        return $adjustment->load('product', 'user');
    }

    
    public function update(Request $request, InventoryAdjustment $adjustment)
    {
        $validated = $request->validate([
            'reason' => 'required|string',
        ]);

        $adjustment->update($validated);
        return response()->json($adjustment);
    }

    
    public function destroy(InventoryAdjustment $adjustment)
    {
        
        
        $adjustment->delete();
        return response()->json(['message' => 'Adjustment record deleted']);
    }
}
