<?php

namespace App\Http\Requests\Payment;

use Illuminate\Foundation\Http\FormRequest;

class GenerateKhqrRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:0.01'],
            'currency' => ['nullable', 'string', 'in:USD,KHR'],
            'sale_id' => ['nullable', 'integer'],
            'bill_number' => ['nullable', 'string', 'max:64'],
            'merchant_id' => ['nullable', 'string', 'max:128'],
            'merchant_name' => ['nullable', 'string', 'max:100'],
            'merchant_city' => ['nullable', 'string', 'max:64'],
            'bank_name' => ['nullable', 'string', 'max:64'],
        ];
    }
}

