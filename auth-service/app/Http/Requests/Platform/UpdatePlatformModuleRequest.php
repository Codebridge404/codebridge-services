<?php

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePlatformModuleRequest extends FormRequest
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
            'name' => ['sometimes', 'required', 'string', 'max:128'],
            'monogram' => ['nullable', 'string', 'max:8'],
            'category' => ['sometimes', 'required', 'string', 'max:64'],
            'description' => ['sometimes', 'required', 'string'],
            'icon' => ['nullable', 'string', 'max:64'],
            'logo_url' => ['nullable', 'string', 'max:255'],
            'href' => ['sometimes', 'required', 'string', 'max:255'],
            'bg_pill' => ['nullable', 'string', 'max:128'],
            'card_bg' => ['nullable', 'string', 'max:255'],
            'is_core' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer'],
        ];
    }
}
