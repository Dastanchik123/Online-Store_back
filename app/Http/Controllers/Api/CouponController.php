<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use Illuminate\Http\Request;

class CouponController extends Controller
{
    public function index()
    {
        return response()->json(Coupon::latest()->get());
    }

    public function validateCoupon(Request $request)
    {
        $request->validate([
            'code'   => 'required|string',
            'amount' => 'required|numeric',
        ]);

        $coupon = Coupon::where('code', $request->code)->first();

        if (! $coupon) {
            return response()->json(['message' => 'Купон не найден'], 404);
        }

        if (! $coupon->isValid()) {
            return response()->json(['message' => 'Купон недействителен или истек'], 400);
        }

        if ($request->amount < $coupon->min_order_amount) {
            return response()->json(['message' => "Минимальная сумма заказа для этого купона: {$coupon->min_order_amount}"], 400);
        }

        return response()->json([
            'valid' => true,
            'code'  => $coupon->code,
            'type'  => $coupon->type,
            'value' => $coupon->value,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'code'             => 'required|string|unique:coupons',
            'type'             => 'required|in:fixed,percent',
            'value'            => array_filter(['required', 'numeric', 'min:0', $this->percentLimit($request->input('type'))]),
            'min_order_amount' => 'nullable|numeric|min:0',
            'is_active'        => 'boolean',
            'expires_at'       => 'nullable|date',
        ]);

        $coupon = Coupon::create($validated);
        return response()->json($coupon, 201);
    }

    public function update(Request $request, Coupon $coupon)
    {
        $effectiveType = $request->input('type', $coupon->type);

        $validated = $request->validate([
            'code'             => 'sometimes|required|string|unique:coupons,code,' . $coupon->id,
            'type'             => 'sometimes|required|in:fixed,percent',
            'value'            => array_filter(['sometimes', 'required', 'numeric', 'min:0', $this->percentLimit($effectiveType)]),
            'min_order_amount' => 'nullable|numeric|min:0',
            'is_active'        => 'boolean',
            'expires_at'       => 'nullable|date',
        ]);

        $coupon->update($validated);
        return response()->json($coupon);
    }

    // Скидка в процентах не должна превышать 100 — иначе купон может обнулить
    // или увести заказ в минус ниже себестоимости.
    private function percentLimit(?string $type): string
    {
        return $type === 'percent' ? 'max:100' : '';
    }

    public function destroy(Coupon $coupon)
    {
        $coupon->delete();
        return response()->json(['message' => 'Coupon deleted']);
    }
}
