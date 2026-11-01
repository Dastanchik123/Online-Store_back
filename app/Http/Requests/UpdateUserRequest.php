<?php

namespace App\Http\Requests;

use App\Support\Phone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
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

    public function messages()
    {
        return ['phone.unique' => 'Этот номер телефона уже есть у другого клиента.'];
    }

    public function rules()
    {
        $user = $this->route('user');

        return [
            'name'        => 'sometimes|string|max:255',
            'email'       => 'sometimes|nullable|email|unique:users,email,' . $user->id,
            'role'        => ['sometimes', 'string', Rule::exists('roles', 'name')],
            'phone'       => ['nullable', 'string', 'max:32', Rule::unique('users', 'phone')->ignore($user->id)],
            'terminal_id' => 'nullable|string',
            'password'    => 'nullable|string|min:8',
            'avatar'      => 'nullable|image|mimes:jpeg,png,jpg,webp|max:20480',
            // Пустая строка = снять PIN/бейдж, отсутствие поля = не трогать
            'staff_pin'   => 'nullable|digits_between:4,8',
            'badge_code'  => 'nullable|string|min:4|max:64',
        ];
    }
}
