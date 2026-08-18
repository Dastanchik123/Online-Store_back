<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCouponRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        $coupon        = $this->route('coupon');
        $effectiveType = $this->input('type', $coupon->type);

        return [
            'code'             => 'sometimes|required|string|unique:coupons,code,' . $coupon->id,
            'type'             => 'sometimes|required|in:fixed,percent',
            'value'            => array_filter(['sometimes', 'required', 'numeric', 'min:0', $this->percentLimit($effectiveType)]),
            'min_order_amount' => 'nullable|numeric|min:0',
            'is_active'        => 'boolean',
            'expires_at'       => 'nullable|date',
        ];
    }

    // Скидка в процентах не должна превышать 100 — иначе купон может обнулить
    // или увести заказ в минус ниже себестоимости.
    private function percentLimit(?string $type): string
    {
        return $type === 'percent' ? 'max:100' : '';
    }
}
