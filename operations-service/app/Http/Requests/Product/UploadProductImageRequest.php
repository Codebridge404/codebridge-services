<?php

namespace App\Http\Requests\Product;

use App\Traits\ResolvesTenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Validator;

class UploadProductImageRequest extends FormRequest
{
    use ResolvesTenantContext;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->getTenantId($this, true);

        $id = $this->route('id');
        if ($id !== null) {
            $product = DB::table('products')->where('id', $id)->first();
            if ($product) {
                $this->authorizeTenantResource($product->tenant_id, $this, 'Product');
            }
        }
    }

    public function rules(): array
    {
        return [
            'image' => ['nullable', 'required_without:image_url', 'file', 'image', 'mimes:jpeg,jpg,png,gif,webp', 'max:10240'],
            'image_url' => ['nullable', 'required_without:image', 'string', 'max:2048'],
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
