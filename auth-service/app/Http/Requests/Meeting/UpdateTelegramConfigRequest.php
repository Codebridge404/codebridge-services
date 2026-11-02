<?php

namespace App\Http\Requests\Meeting;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTelegramConfigRequest extends FormRequest
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
            'bot_enabled' => ['nullable', 'boolean'],
            'bot_token' => ['nullable', 'string'],
            'bot_username' => ['nullable', 'string', 'max:100'],
            'default_chat_id' => ['nullable', 'string', 'max:100'],
            'default_reminder_minutes' => ['nullable', 'integer', 'min:1', 'max:120'],
        ];
    }
}
