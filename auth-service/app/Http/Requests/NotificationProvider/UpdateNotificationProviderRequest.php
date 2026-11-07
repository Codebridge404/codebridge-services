<?php

namespace App\Http\Requests\NotificationProvider;

use Illuminate\Foundation\Http\FormRequest;

class UpdateNotificationProviderRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:100'],
            'channel' => ['sometimes', 'string', 'max:20', 'in:email,telegram,slack,sms,webhook,push,whatsapp'],
            'provider' => ['sometimes', 'string', 'max:50'],
            'environment' => ['sometimes', 'string', 'max:20', 'in:production,staging,demo,development,sandbox,all'],
            'credentials' => ['sometimes'],
            'settings' => ['sometimes', 'array'],
            'is_enabled' => ['sometimes', 'boolean'],
            'tenant_id' => ['nullable', 'integer', 'exists:tenants,id'],
        ];
    }
}

