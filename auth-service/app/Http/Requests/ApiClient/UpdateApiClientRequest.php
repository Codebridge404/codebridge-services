<?php

namespace App\Http\Requests\ApiClient;

use App\Models\Security\ApiClient;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $clientId = $this->route('id');
        $client = is_numeric($clientId)
            ? ApiClient::find($clientId)
            : ApiClient::where('uuid', $clientId)->first();

        return [
            'name' => ['sometimes', 'string', 'max:100'],
            'client_type' => ['sometimes', 'string', 'in:web,pos,mobile,server,first_party,public,confidential,other'],
            'client_key' => [
                'sometimes',
                'required',
                'string',
                'max:64',
                Rule::unique('api_clients', 'client_key')->ignore($client?->id),
            ],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
