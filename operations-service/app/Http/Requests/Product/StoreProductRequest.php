<?php

namespace App\Http\Requests\Product;

use App\Traits\ResolvesTenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreProductRequest extends FormRequest
{
    use ResolvesTenantContext;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->getTenantId($this, true);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'sku' => ['required', 'string', 'max:100'],
            'barcode' => ['nullable', 'string', 'max:100'],
            'selling_price' => ['required', 'numeric', 'min:0'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'category_id' => ['nullable', 'string', 'max:36'],
            'brand_id' => ['nullable', 'string', 'max:36'],
            'expiry_date' => ['nullable', 'date'],
            'min_reorder_point' => ['nullable', 'integer', 'min:0'],
            'description' => ['nullable', 'string'],
            'initial_stock' => ['nullable', 'numeric', 'min:0'],
            'image_url' => ['nullable', 'string', 'max:2048'],
            'image' => ['nullable', 'file', 'image', 'mimes:jpeg,jpg,png,gif,webp', 'max:10240'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if ($this->hasFile('image')) {
                $file = $this->file('image');
                $ext = strtolower((string) $file?->getClientOriginalExtension());
                $mime = strtolower((string) $file?->getMimeType());
                if ($ext === 'svg' || str_contains($mime, 'svg')) {
                    $v->errors()->add('image', 'SVG uploads are not permitted.');
                }
            }

            $url = $this->input('image_url');
            if (is_string($url) && trim($url) !== '') {
                $trimmed = trim($url);
                if (stripos($trimmed, 'data:') === 0 && !preg_match('/^data:image\/(jpeg|jpg|png|gif|webp);base64,/i', $trimmed)) {
                    $v->errors()->add('image_url', 'Unsupported or unsafe image data URI format.');
                } elseif (preg_match('/\.svg(?:$|[?#])/i', parse_url($trimmed, PHP_URL_PATH) ?: $trimmed)) {
                    $v->errors()->add('image_url', 'SVG image URLs are not permitted.');
                }
            }
        });
    }
}

