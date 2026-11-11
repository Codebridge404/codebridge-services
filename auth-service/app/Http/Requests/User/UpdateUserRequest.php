<?php

namespace App\Http\Requests\User;

use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users')->ignore($this->route('id'))],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30', Rule::unique('users')->ignore($this->route('id'))],
            'password' => ['nullable', 'string', 'min:6'],
            'role' => ['sometimes', 'string', Rule::in(UserController::getAllowedRoles())],
            'role_id' => ['sometimes', 'nullable', 'integer'],
            'outlet_id' => ['sometimes', 'nullable', 'string'],
            'pin_code' => ['sometimes', 'nullable', 'string', 'max:10'],
            'avatar_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'is_active' => ['sometimes', 'boolean'],
            'tenant_id' => ['sometimes', 'nullable'],
        ];
    }
}
