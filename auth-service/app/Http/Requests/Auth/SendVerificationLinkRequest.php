<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class SendVerificationLinkRequest extends FormRequest
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
             * The email address of the account to receive the verification link.
             * (Optional if authenticated via Bearer token, required if unauthenticated).
             * @example admin@codebridge.dev
             */
            'email' => ['nullable', 'string', 'email'],

            /**
             * Whether to immediately dispatch the email to Mailpit (default: true).
             * @example true
             */
            'send_mail' => ['nullable', 'boolean'],
        ];
    }
}

