<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCategoryRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        $categoryId = $this->route('category')->id ?? $this->route('category');
        
        return [
            'name' => 'sometimes|required|string|max:255',
            'slug' => 'sometimes|nullable|string|max:255|unique:categories,slug,' . $categoryId,
            'description' => 'nullable|string',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:20480',
            // Родитель не может быть самой категорией или её потомком: иначе цикл,
            // и категория выпадает из дерева (не корень и ничей не ребёнок)
            'parent_id' => ['nullable', 'exists:categories,id', function ($attribute, $value, $fail) use ($categoryId) {
                $seen = [];
                for ($id = (int) $value; $id && ! isset($seen[$id]); $id = (int) \App\Models\Category::whereKey($id)->value('parent_id')) {
                    if ($id === (int) $categoryId) {
                        $fail('Нельзя сделать родителем саму категорию или её подкатегорию.');

                        return;
                    }
                    $seen[$id] = true;
                }
            }],
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}

