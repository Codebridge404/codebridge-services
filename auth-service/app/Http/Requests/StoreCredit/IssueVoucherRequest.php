<?php

namespace App\Http\Requests\StoreCredit;

use Illuminate\Foundation\Http\FormRequest;

class IssueVoucherRequest extends FormRequest
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
            'value' => ['required', 'numeric', 'min:0.01'],
            'title' => ['nullable', 'string', 'max:100'],
            'validity_days' => ['nullable', 'integer', 'min:1', 'max:730'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'notes' => ['nullable', 'string', 'max:255'],
        ];
    }
}
