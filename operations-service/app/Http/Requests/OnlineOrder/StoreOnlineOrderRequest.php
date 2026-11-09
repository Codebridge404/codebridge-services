<?php

namespace App\Http\Requests\OnlineOrder;

use Illuminate\Foundation\Http\FormRequest;

class StoreOnlineOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_phone' => ['required', 'string', 'max:50'],
            'delivery_type' => ['required', 'string', 'in:pickup,delivery'],
            'delivery_address' => ['nullable', 'string', 'max:500'],
            'payment_method' => ['nullable', 'string', 'in:khqr,cash,store_credit'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', 'integer', 'min:1'],
            'items.*.product_name' => ['nullable', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'numeric', 'min:1', 'max:1000'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}

