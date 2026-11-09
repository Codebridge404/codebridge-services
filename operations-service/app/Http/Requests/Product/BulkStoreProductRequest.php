<?php

namespace App\Http\Requests\Product;

use App\Traits\ResolvesTenantContext;
use Illuminate\Foundation\Http\FormRequest;

class BulkStoreProductRequest extends FormRequest
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
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.name' => ['required', 'string', 'max:255'],
            'items.*.sku' => ['required', 'string', 'max:100'],
            'items.*.barcode' => ['nullable', 'string', 'max:100'],
            'items.*.selling_price' => ['required', 'numeric', 'min:0'],
            'items.*.cost_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.category_id' => ['nullable', 'integer'],
            'items.*.category_name' => ['nullable', 'string', 'max:255'],
            'items.*.min_reorder_point' => ['nullable', 'integer', 'min:0'],
            'items.*.description' => ['nullable', 'string'],
            'items.*.initial_stock' => ['nullable', 'integer', 'min:0'],
        ];
    }
}

