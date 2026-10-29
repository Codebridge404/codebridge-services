<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
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
            'email' => ['required_without_all:phone,login,identifier', 'nullable', 'string'],
            'phone' => ['required_without_all:email,login,identifier', 'nullable', 'string'],
            'login' => ['required_without_all:email,phone,identifier', 'nullable', 'string'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string'],
        ];
    }

    /**
     * Custom validation error messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required_without_all' => 'The email or phone number field is required.',
            'phone.required_without_all' => 'The email or phone number field is required.',
            'login.required_without_all' => 'The email or phone number field is required.',
            'identifier.required_without_all' => 'The email or phone number field is required.',
            'password.required' => 'The password field is required.',
        ];
    }
}
