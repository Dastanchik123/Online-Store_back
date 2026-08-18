<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrderRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'shipping_address_id' => 'nullable|exists:addresses,id',
            'billing_address_id'  => 'nullable|exists:addresses,id',
            'shipping_address'    => 'nullable|array',
            'billing_address'     => 'nullable|array',
            'notes'               => 'nullable|string',
            'is_debt'             => 'boolean',
            'due_date'            => 'nullable|date|after:today',
            'initial_payment'     => 'nullable|numeric|min:0',
            'payment_method'      => 'nullable|string',
            'coupon_code'         => 'nullable|string',
        ];
    }
}

