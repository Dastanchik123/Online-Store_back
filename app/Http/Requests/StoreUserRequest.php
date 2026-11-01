<?php

namespace App\Http\Requests;

use App\Support\Phone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    // Номер сравнивается в едином виде (+996…), как хранится в базе
    protected function prepareForValidation()
    {
        if ($this->filled('phone')) {
            $this->merge(['phone' => Phone::normalize($this->input('phone')) ?? $this->input('phone')]);
        }
    }

    public function rules()
    {
        return [
            'name'        => 'required|string|max:255',
            // Сотрудники входят по email и паролю, покупатели — по номеру
            'email'       => 'required_unless:role,user|nullable|string|email|max:255|unique:users',
            'password'    => 'required_unless:role,user|nullable|string|min:8',
            'role'        => ['required', 'string', Rule::exists('roles', 'name')],
            'phone'       => ['required_if:role,user', 'nullable', 'string', 'max:32', Rule::unique('users', 'phone')],
            'terminal_id' => 'nullable|string',
        ];
    }

    public function messages()
    {
        return ['phone.unique' => 'Этот номер телефона уже есть у другого клиента.'];
    }
}
