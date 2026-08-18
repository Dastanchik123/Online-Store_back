<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePurchaseRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'supplier_id'        => 'sometimes|required|exists:suppliers,id',
            'paid_amount'        => 'sometimes|numeric|min:0',
            'notes'              => 'nullable|string',
            'items'              => 'sometimes|array',
            'items.*.product_id' => 'required_with:items|exists:products,id',
            'items.*.quantity'   => 'required_with:items|numeric|min:0.001',
            'items.*.is_package' => 'nullable|boolean',
            'items.*.buy_price'  => 'required_with:items|numeric|min:0',
            'items.*.sale_price' => 'nullable|numeric|min:0',
        ];
    }
}
