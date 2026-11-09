<?php

namespace App\Http\Requests\OnlineOrder;

use Illuminate\Foundation\Http\FormRequest;

class UpdateOnlineOrderStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'fulfillment_status' => ['required', 'string', 'in:pending,confirmed,preparing,ready,completed,cancelled'],
        ];
    }
}

