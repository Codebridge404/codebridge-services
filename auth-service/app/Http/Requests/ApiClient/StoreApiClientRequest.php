<?php

namespace App\Http\Requests\ApiClient;

use Illuminate\Contracts\Validation\ValidationRule;
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
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'client_type' => ['required', 'string', 'in:web,pos,mobile,server,first_party,public,confidential,other'],
            'uuid' => ['nullable', 'string', 'uuid', 'unique:api_clients,uuid'],
            'client_key' => ['nullable', 'string', 'max:64', 'unique:api_clients,client_key'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
