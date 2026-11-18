<?php

namespace App\Http\Requests\Platform;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePlatformModuleRequest extends FormRequest
{
    use ValidatesPlatformLogo;

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
            'name' => ['sometimes', 'required', 'string', 'max:128'],
            'monogram' => ['nullable', 'string', 'max:8'],
            'category' => ['sometimes', 'required', 'string', 'max:64'],
            'description' => ['sometimes', 'required', 'string'],
            'icon' => ['nullable', 'string', 'max:64'],
            'logo_url' => ['nullable', 'string', 'max:500'],
            'logo_data' => ['nullable', 'string', 'max:14000000'],
            'logo' => ['nullable', 'file', 'image', 'mimes:png,jpg,jpeg,webp,gif', 'max:5120'],
            'href' => ['sometimes', 'required', 'string', 'max:255'],
            'bg_pill' => ['nullable', 'string', 'max:128'],
            'card_bg' => ['nullable', 'string', 'max:255'],
            'is_core' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer'],
        ];
    }
}
