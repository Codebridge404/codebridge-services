<?php

namespace App\Http\Requests\Tenant;

use App\Services\ImageService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

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
            'logo_url' => ['nullable', 'string', 'max:14000000'],
            'logo' => ['nullable', 'file', 'image', 'mimes:jpeg,png,jpg,webp,gif', 'max:10240'],
            'receipt_footer' => ['nullable', 'string', 'max:500'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'country' => ['nullable', 'string', 'max:10'],
            'currency' => ['nullable', 'string', 'max:10'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if ($this->hasFile('logo')) {
                try {
                    app(ImageService::class)->assertSafeRasterSource($this->file('logo'));
                } catch (\InvalidArgumentException $e) {
                    $v->errors()->add('logo', $e->getMessage());
                }
            }

            $logoUrl = $this->input('logo_url');
            if (is_string($logoUrl) && trim($logoUrl) !== '' && !ImageService::isSafeImageUrlOrDataUri($logoUrl)) {
                $v->errors()->add('logo_url', 'Unsupported or unsafe logo URL/data URI format (SVGs are not permitted).');
            }
        });
    }
}
