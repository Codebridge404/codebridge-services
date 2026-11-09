<?php

namespace App\Http\Requests\Auth;

use App\Services\ImageService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UploadProfileImageRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Pre-validation normalization: map avatar_url or file key to avatar if present.
     */
    protected function prepareForValidation(): void
    {
        if (!$this->hasFile('avatar')) {
            if ($this->hasFile('avatar_url')) {
                $this->files->set('avatar', $this->file('avatar_url'));
            } elseif ($this->hasFile('file')) {
                $this->files->set('avatar', $this->file('file'));
            } elseif ($this->hasFile('image')) {
                $this->files->set('avatar', $this->file('image'));
            }
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'avatar' => ['required', 'file', 'image', 'mimes:jpeg,png,jpg,webp,gif', 'max:10240'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if ($this->hasFile('avatar')) {
                try {
                    app(ImageService::class)->assertSafeRasterSource($this->file('avatar'));
                } catch (\InvalidArgumentException $e) {
                    $v->errors()->add('avatar', $e->getMessage());
                }
            }
        });
    }

    /**
     * Custom validation messages.
     */
    public function messages(): array
    {
        return [
            'avatar.required' => 'Please select an image file to upload.',
            'avatar.file'     => 'The avatar must be a valid file.',
            'avatar.mimes'    => 'The avatar must be a raster image of type: jpeg, png, jpg, webp, gif.',
            'avatar.max'      => 'The avatar image size must not exceed 10MB.',
        ];
    }
}
