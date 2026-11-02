<?php

namespace App\Http\Requests\GiftCard;

use Illuminate\Foundation\Http\FormRequest;

class StoreGiftCardRequest extends FormRequest
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
            'card_number' => ['nullable', 'string', 'max:50'],
            'card_code' => ['nullable', 'string', 'max:50'],
            'initial_value' => ['nullable', 'numeric', 'min:0.01'],
            'balance' => ['nullable', 'numeric', 'min:0.01'],
            'currency' => ['nullable', 'string', 'size:3'],
            'customer' => ['nullable', 'string'],
            'expires_at' => ['nullable', 'date'],
        ];
    }
}
