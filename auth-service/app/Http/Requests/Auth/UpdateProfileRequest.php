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
        $rules = [
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'pin_code' => ['sometimes', 'string', 'regex:/^[0-9]{4,10}$/'],
            'avatar' => ['sometimes', 'file', 'mimes:jpeg,png,jpg,webp,gif', 'max:10240'],
            'avatar_url' => $this->hasFile('avatar_url')
                ? ['file', 'mimes:jpeg,png,jpg,webp,gif', 'max:10240']
                : ['sometimes', 'nullable', 'string', 'max:14000000'],
        ];

      

        return $rules;
    }
}
