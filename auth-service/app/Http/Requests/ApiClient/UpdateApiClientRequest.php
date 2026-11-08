<?php

namespace App\Http\Requests\ApiClient;

use Illuminate\Foundation\Http\FormRequest;

class UpdateApiClientRequest extends FormRequest
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
            'client_type' => ['sometimes', 'string', 'in:web,pos,mobile,server,other'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}

