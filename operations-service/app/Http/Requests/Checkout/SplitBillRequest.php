<?php

namespace App\Http\Requests\Checkout;

use Illuminate\Foundation\Http\FormRequest;

class SplitBillRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'table_id' => ['nullable', 'integer', 'min:1'],
            'sale_id' => ['nullable', 'integer', 'min:1'],
            'split_type' => ['required', 'in:equal,itemized'],
            'splits_count' => ['nullable', 'integer', 'min:2', 'max:20'],
            'default_tender_type' => ['nullable', 'string', 'max:50'],
            'splits' => ['nullable', 'array'],
            'splits.*.customer_id' => ['nullable', 'integer', 'min:1'],
            'splits.*.customer_name' => ['nullable', 'string', 'max:255'],
            'splits.*.tender_type' => ['nullable', 'string', 'max:50'],
            'splits.*.items' => ['nullable', 'array'],
        ];
    }
}

