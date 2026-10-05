<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\WeightedBarcodeService;
use Illuminate\Http\Request;

class BarcodeController extends Controller
{
    public function parse(Request $request, WeightedBarcodeService $weightedBarcodeService)
    {
        $request->validate([
            'code' => 'required|string',
        ]);

        $parsed = $weightedBarcodeService->parse($request->code);

        if ($parsed === null) {
            return response()->json(['message' => 'Штрихкод не распознан как весовой'], 422);
        }

        $product = Product::find($parsed['product_id']);

        return response()->json([
            'product_id' => $parsed['product_id'],
            'weight_kg'  => $parsed['weight_kg'],
            'product'    => $product,
        ]);
    }
}
