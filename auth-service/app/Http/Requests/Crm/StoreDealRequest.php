<?php

namespace App\Http\Requests\Crm;

use Illuminate\Foundation\Http\FormRequest;

class StoreDealRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
            'value' => ['required', 'numeric', 'min:0'],
            'stage' => ['nullable', 'string', 'in:lead,qualified,proposal,negotiation,won,lost'],
            'probability' => ['nullable', 'integer', 'min:0', 'max:100'],
            'owner_name' => ['nullable', 'string', 'max:255'],
            'expected_close_date' => ['nullable', 'date'],
        ];
    }
}
