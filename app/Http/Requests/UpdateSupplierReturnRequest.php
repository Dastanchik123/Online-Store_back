<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSupplierReturnRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'supplier_id'                => 'sometimes|required|exists:suppliers,id',
            'return_date'                => 'nullable|date',
            'reason'                     => 'nullable|string|max:255',
            'notes'                      => 'nullable|string',
            'items'                      => 'sometimes|required|array|min:1',
            'items.*.purchase_item_id'   => 'required_with:items|exists:purchase_items,id',
            'items.*.quantity'           => 'required_with:items|numeric|min:0.001',
        ];
    }
}
