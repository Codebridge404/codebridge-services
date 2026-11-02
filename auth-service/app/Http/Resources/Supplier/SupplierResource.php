<?php

namespace App\Http\Resources\Supplier;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupplierResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => data_get($this->resource, 'id'),
            'uuid' => data_get($this->resource, 'uuid'),
            'tenant_id' => data_get($this->resource, 'tenant_id'),
            'name' => data_get($this->resource, 'name'),
            'company_name' => data_get($this->resource, 'company_name'),
            'code' => data_get($this->resource, 'code'),
            'contact_name' => data_get($this->resource, 'company_name') ?? data_get($this->resource, 'contact_name') ?? data_get($this->resource, 'contact_person'),
            'email' => data_get($this->resource, 'email'),
            'phone' => data_get($this->resource, 'phone'),
            'address' => data_get($this->resource, 'address'),
            'is_active' => (bool) data_get($this->resource, 'is_active', true),
            'created_at' => data_get($this->resource, 'created_at'),
            'updated_at' => data_get($this->resource, 'updated_at'),
        ];
    }
}
