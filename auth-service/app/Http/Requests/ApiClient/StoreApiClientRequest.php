<?php

namespace App\Http\Requests\ApiClient;

use Illuminate\Foundation\Http\FormRequest;

class StoreApiClientRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:100'],
            'client_type' => ['required', 'string', 'in:web,pos,mobile,server,other'],
            'client_key' => ['nullable', 'string', 'max:64', 'unique:api_clients,client_key'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
