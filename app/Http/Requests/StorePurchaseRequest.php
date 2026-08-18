<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePurchaseRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'supplier_id'        => 'required|exists:suppliers,id',
            'items'              => 'required|array',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity'   => 'required|numeric|min:0.001',
            'items.*.is_package' => 'nullable|boolean',
            'items.*.buy_price'  => 'required|numeric|min:0',
            'items.*.sale_price' => 'nullable|numeric|min:0',
            'paid_amount'        => 'required|numeric|min:0',
            'notes'              => 'nullable|string',
        ];
    }
}
