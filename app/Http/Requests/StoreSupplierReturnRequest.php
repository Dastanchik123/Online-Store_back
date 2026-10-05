<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSupplierReturnRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'supplier_id'                => 'required|exists:suppliers,id',
            'return_date'                => 'nullable|date',
            'reason'                     => 'nullable|string|max:255',
            'notes'                      => 'nullable|string',
            'items'                      => 'required|array|min:1',
            'items.*.purchase_item_id'   => 'required|exists:purchase_items,id',
            'items.*.quantity'           => 'required|numeric|min:0.001',
        ];
    }
}
