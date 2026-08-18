<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCouponRequest;
use App\Http\Requests\UpdateCouponRequest;
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

    public function store(StoreCouponRequest $request)
    {
        $coupon = Coupon::create($request->validated());
        return response()->json($coupon, 201);
    }

    public function update(UpdateCouponRequest $request, Coupon $coupon)
    {
        $coupon->update($request->validated());
        return response()->json($coupon);
    }

    public function destroy(Coupon $coupon)
    {
        $coupon->delete();
        return response()->json(['message' => 'Coupon deleted']);
    }
}
