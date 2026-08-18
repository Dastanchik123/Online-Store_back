<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCouponRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'code'             => 'required|string|unique:coupons',
            'type'             => 'required|in:fixed,percent',
            'value'            => array_filter(['required', 'numeric', 'min:0', $this->percentLimit($this->input('type'))]),
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
