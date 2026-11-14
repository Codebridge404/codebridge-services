<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class TwoFactorSetupRequest extends FormRequest
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
            /**
             * Current account password.
             * Required only if 2FA is already enabled on your account to reconfigure/replace your active secret.
             * Optional for first-time 2FA setup.
             * @example Code@2026
             */
            'password' => ['nullable', 'string'],
        ];
    }

    /**
     * Custom attribute descriptions for validation messages.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'password' => 'current password',
        ];
    }
}
