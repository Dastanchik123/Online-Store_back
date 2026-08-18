<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        $user = $this->route('user');

        return [
            'name'        => 'sometimes|string|max:255',
            'email'       => 'sometimes|email|unique:users,email,' . $user->id,
            'role'        => ['sometimes', 'string', Rule::exists('roles', 'name')],
            'phone'       => 'nullable|string',
            'terminal_id' => 'nullable|string',
            'password'    => 'nullable|string|min:8',
            'avatar'      => 'nullable|image|max:10240',
        ];
    }
}
