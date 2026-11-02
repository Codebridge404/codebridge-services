<?php

namespace App\Http\Requests\StoreCredit;

use Illuminate\Foundation\Http\FormRequest;

class TopUpStoreCreditRequest extends FormRequest
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
            'amount_paid' => ['required', 'numeric', 'min:0.01'],
            'bonus_credit' => ['nullable', 'numeric', 'min:0'],
            'payment_method' => ['nullable', 'string', 'in:cash,card,khqr,transfer'],
            'payment_reference' => ['nullable', 'string', 'max:100'],
            'validity_days' => ['nullable', 'integer', 'min:1', 'max:1825'],
            'notes' => ['nullable', 'string', 'max:255'],
            'outlet_id' => ['nullable', 'integer'],
        ];
    }
}
