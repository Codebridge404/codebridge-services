<?php

namespace App\Http\Requests\Auth;

use App\Services\ImageService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateProfileRequest extends FormRequest
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
        $rules = [
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'pin_code' => ['sometimes', 'string', 'regex:/^[0-9]{4,10}$/'],
            'avatar' => ['sometimes', 'file', 'image', 'mimes:jpeg,png,jpg,webp,gif', 'max:10240'],
            'avatar_url' => $this->hasFile('avatar_url')
                ? ['file', 'image', 'mimes:jpeg,png,jpg,webp,gif', 'max:10240']
                : ['sometimes', 'nullable', 'string', 'max:14000000'],
        ];

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            foreach (['avatar', 'avatar_url'] as $fileKey) {
                if ($this->hasFile($fileKey)) {
                    try {
                        app(ImageService::class)->assertSafeRasterSource($this->file($fileKey));
                    } catch (\InvalidArgumentException $e) {
                        $v->errors()->add($fileKey, $e->getMessage());
                    }
                }
            }

            if (!$this->hasFile('avatar_url')) {
                $avatarUrl = $this->input('avatar_url');
                if (is_string($avatarUrl) && trim($avatarUrl) !== '' && !ImageService::isSafeImageUrlOrDataUri($avatarUrl)) {
                    $v->errors()->add('avatar_url', 'Unsupported or unsafe avatar URL/data URI format (SVGs are not permitted).');
                }
            }
        });
    }
}
