<?php

namespace App\Http\Requests\Meeting;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrUpdateMeetingMinutesRequest extends FormRequest
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
            'content' => ['required', 'string'],
            'action_items' => ['nullable', 'array'],
            'status' => ['nullable', 'string', 'in:draft,published'],
            'agenda' => ['nullable', 'string'],
            'summary' => ['nullable', 'string'],
            'key_decisions' => ['nullable', 'string'],
        ];
    }
}
