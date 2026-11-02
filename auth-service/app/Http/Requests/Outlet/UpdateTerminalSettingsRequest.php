<?php

namespace App\Http\Requests\Outlet;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTerminalSettingsRequest extends FormRequest
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
            'business_type' => ['nullable', 'string', 'in:retail_mart,restaurant_fnb,cafe_qsr,boutique_retail'],
            'is_kds_enabled' => ['nullable', 'boolean'],
            'is_cfd_enabled' => ['nullable', 'boolean'],
            'terminal_settings' => ['nullable', 'array'],
        ];
    }
}
