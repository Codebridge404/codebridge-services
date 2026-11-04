<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class AcceptInviteRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Prepare the data for validation, allowing token to be supplied via header.
     */
    protected function prepareForValidation(): void
    {
        if (!$this->has('token')) {
            $token = $this->header('token')
                ?: $this->header('Token')
                ?: $this->header('x-token')
                ?: $this->bearerToken();

            if ($token && str_starts_with($token, 'Bearer ')) {
                $token = trim(substr($token, 7));
            }

            if ($token) {
                $this->merge(['token' => $token]);
            }
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:6'],
            'pin_code' => ['nullable', 'string', 'min:4', 'max:8'],
            'phone' => ['nullable', 'string', 'max:30'],
        ];
    }
}
