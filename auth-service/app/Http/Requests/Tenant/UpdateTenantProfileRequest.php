<?php

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTenantProfileRequest extends FormRequest
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
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'business_type' => ['nullable', 'string', 'in:retail_mart,restaurant_fnb,cafe_qsr,boutique_retail'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'logo_url' => ['nullable', 'string'],
            'logo' => ['nullable', 'file', 'mimes:jpeg,png,jpg,webp,svg', 'max:10240'],
            'receipt_footer' => ['nullable', 'string', 'max:500'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'country' => ['nullable', 'string', 'max:10'],
            'currency' => ['nullable', 'string', 'max:10'],
        ];
    }
}
