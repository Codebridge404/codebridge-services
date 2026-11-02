<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Validation\Rule;

class AttachUserRequest extends FormRequest
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
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['nullable', 'integer'],
            'email' => ['nullable', 'email'],
            'role' => ['required', 'string', Rule::in(UserController::getAllowedRoles())],
            'role_id' => ['nullable', 'integer'],
            'outlet_id' => ['nullable', 'string'],
            'pin_code' => ['nullable', 'string', 'max:10'],
        ];
    }
}
