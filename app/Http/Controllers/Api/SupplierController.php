<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SupplierController extends Controller
{
    
    public function index(): JsonResponse
    {
        try {
            $suppliers = Supplier::withCount('purchases')
                ->withSum('purchases', 'total_amount')
                ->latest()
                ->paginate(15);

            return response()->json($suppliers);
        } catch (\Exception $e) {
            Log::error("Failed to fetch suppliers: " . $e->getMessage());
            return response()->json(['message' => 'Error loading suppliers list'], 500);
        }
    }

    
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'           => 'required|string|max:255',
            'contact_person' => 'nullable|string|max:255',
            'phone'          => 'nullable|string|max:20',
            'email'          => 'nullable|email|max:255|unique:suppliers,email',
            'address'        => 'nullable|string',
        ]);

        try {
            $supplier = Supplier::create($validated);
            return response()->json([
                'message' => 'Supplier created successfully',
                'data'    => $supplier,
            ], 201);
        } catch (\Exception $e) {
            Log::error("Supplier creation failed: " . $e->getMessage());
            return response()->json(['message' => 'Could not create supplier'], 500);
        }
    }

    
    public function show(Supplier $supplier): JsonResponse
    {
        
        $supplier->load(['purchases' => function ($query) {
            $query->latest()->limit(10);
        }]);

        return response()->json($supplier);
    }

    
    public function update(Request $request, Supplier $supplier): JsonResponse
    {
        $validated = $request->validate([
            'name'           => 'sometimes|required|string|max:255',
            'contact_person' => 'nullable|string|max:255',
            'phone'          => 'nullable|string|max:20',
            'email'          => 'nullable|email|max:255|unique:suppliers,email,' . $supplier->id,
            'address'        => 'nullable|string',
        ]);

        try {
            $supplier->update($validated);
            return response()->json([
                'message' => 'Supplier updated successfully',
                'data'    => $supplier,
            ]);
        } catch (\Exception $e) {
            Log::error("Supplier update failed: " . $e->getMessage());
            return response()->json(['message' => 'Could not update supplier'], 500);
        }
    }

    
    public function destroy(Supplier $supplier): JsonResponse
    {
        try {
            
            if ($supplier->purchases()->exists()) {
                return response()->json([
                    'message' => 'Integrity Error: Cannot delete supplier with existing purchase records. Consider archiving instead.',
                ], 422);
            }

            $supplier->delete();
            return response()->json(['message' => 'Supplier deleted successfully']);
        } catch (\Exception $e) {
            Log::error("Supplier deletion failed: " . $e->getMessage());
            return response()->json(['message' => 'Could not delete supplier'], 500);
        }
    }
}
