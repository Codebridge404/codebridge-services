<?php

namespace App\Http\Requests\Meeting;

use Illuminate\Foundation\Http\FormRequest;

class StoreAttachmentRequest extends FormRequest
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
            'file' => ['nullable', 'file', 'max:25600'], // 25MB max binary upload
            'file_name' => ['required_without:file', 'nullable', 'string', 'max:255'],
            'file_path' => ['nullable', 'string', 'max:1024'],
            'file_url' => ['nullable', 'string', 'max:1024'],
            'file_type' => ['nullable', 'string', 'max:100'],
            'file_size' => ['nullable', 'integer', 'min:0'],
            'file_hash' => ['nullable', 'string', 'max:64'],
        ];
    }
}
