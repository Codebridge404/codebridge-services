<?php

namespace App\Http\Requests\Meeting;

use Illuminate\Foundation\Http\FormRequest;

class ReportMeetingIssueRequest extends FormRequest
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
            'category' => ['required', 'string', 'in:audio_visual,air_conditioning,cleanliness,network,furniture'],
            'priority' => ['nullable', 'string', 'in:low,medium,high,critical'],
            'description' => ['required', 'string', 'max:1000'],
        ];
    }
}
