<?php

namespace App\Http\Requests\Meeting;

use Illuminate\Foundation\Http\FormRequest;

class StoreMeetingRequest extends FormRequest
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
            'room_id' => ['required', 'integer'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'start_time' => ['required', 'date'],
            'end_time' => ['required', 'date', 'after:start_time'],
            'meeting_type' => ['nullable', 'string'],
            'meeting_link' => ['nullable', 'string'],
            'attendees' => ['nullable', 'array'],
            'materials' => ['nullable', 'array'],
            'materials.*.material_id' => ['required', 'integer', 'distinct', 'exists:meeting_materials,id'],
            'materials.*.quantity' => ['required', 'integer', 'min:1'],
            'attendees.*.user_id' => ['nullable', 'integer', 'exists:users,id'],
            'attendees.*.name' => ['nullable', 'string', 'max:255'],
            'attendees.*.email' => ['nullable', 'email'],
            'attendees.*.role' => ['nullable', 'string'],
            'staff_ids' => ['nullable', 'array'],
            'staff_ids.*' => ['integer', 'distinct', 'exists:meeting_staff,id'],
            'is_recurring' => ['nullable', 'boolean'],
            'recurrence_type' => ['nullable', 'string', 'in:none,daily,weekly,bi_weekly,monthly'],
            'occurrences_count' => ['nullable', 'integer', 'min:1', 'max:52'],
        ];
    }
}
