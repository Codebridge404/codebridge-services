<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

class AdjustStoreCreditRequest extends FormRequest
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
            'amount_change' => ['required', 'numeric'],
            'entry_type' => ['required', 'string', 'in:deposit,refund_credit,checkout_use,points_conversion,adjustment'],
            'reference_id' => ['nullable', 'string'],
            'notes' => ['nullable', 'string', 'max:255'],
        ];
    }
}
