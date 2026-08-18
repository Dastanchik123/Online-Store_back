<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        $product = $this->route('product');

        return [
            'name'              => 'sometimes|required|string|max:255',
            'slug'              => ['sometimes', 'nullable', 'string', 'max:255', Rule::unique('products', 'slug')->whereNull('deleted_at')->ignore($product->id)],
            'description'       => 'nullable|string',
            'short_description' => 'nullable|string',
            'sku'               => ['sometimes', 'required', 'string', 'max:255', Rule::unique('products', 'sku')->whereNull('deleted_at')->ignore($product->id)],
            'purchase_price'    => 'nullable|numeric|min:0',
            'price'             => 'sometimes|required|numeric|min:0',
            'sale_price'        => 'nullable|numeric|min:0',
            'in_stock'          => 'boolean',
            'is_active'         => 'boolean',
            'is_hot'            => 'boolean',

            'image'             => 'nullable|image|mimes:jpeg,png,jpg,webp|max:10240',
            'images'            => 'nullable|array',
            'images.*'          => 'image|mimes:jpeg,png,jpg,webp|max:10240',

            'category_id'       => 'sometimes|required|exists:categories,id',
            'weight'            => 'nullable|numeric|min:0',
            'dimensions'        => 'nullable|string',
            'unit'              => 'nullable|string|max:20',
            'package_unit'      => 'nullable|string|max:20',
            'package_size'      => 'nullable|required_with:package_unit|numeric|min:0.001',
            'package_price'     => 'nullable|required_with:package_unit|numeric|min:0',
            'package_purchase_price' => 'nullable|numeric|min:0',
            'attributes'        => 'nullable|array',
            'hot_order'         => 'nullable|integer',
            'hot_group'         => 'nullable|string|max:50',
        ];
    }
}
