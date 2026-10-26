<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'pin_code' => ['sometimes', 'nullable', 'string', 'max:10'],
            'avatar_url' => ['sometimes', 'nullable', 'string'],
            'remove_avatar' => ['sometimes', 'nullable', 'boolean'],
            'avatar' => ['sometimes', 'nullable', 'file', 'mimes:jpeg,png,jpg,webp,gif,svg', 'max:10240'],
        ];
    }
}
