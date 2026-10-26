<?php

namespace App\Http\Resources\User;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
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
            'outlet_id' => data_get($this->resource, 'outlet_id'),
            'outlet_uuid' => data_get($this->resource, 'outlet_uuid'),
            'name' => data_get($this->resource, 'name'),
            'email' => data_get($this->resource, 'email'),
            'phone' => data_get($this->resource, 'phone'),
            'avatar_url' => data_get($this->resource, 'avatar_url'),
            'role' => data_get($this->resource, 'role'),
            'role_id' => data_get($this->resource, 'role_id'),
            'role_name' => data_get($this->resource, 'role_name'),
            'role_slug' => data_get($this->resource, 'role_slug'),
            'pin_code' => data_get($this->resource, 'pin_code'),
            'is_active' => (bool) data_get($this->resource, 'is_active', true),
            'two_factor_enabled' => (bool) data_get($this->resource, 'two_factor_enabled', false),
            'outlet_name' => data_get($this->resource, 'outlet_name'),
            'tenant_name' => data_get($this->resource, 'tenant_name'),
            'company_name' => data_get($this->resource, 'company_name'),
            'tenant_logo_url' => data_get($this->resource, 'tenant_logo_url'),
            'logo_url' => data_get($this->resource, 'logo_url'),
            'created_at' => data_get($this->resource, 'created_at'),
            'updated_at' => data_get($this->resource, 'updated_at'),
        ];
    }
}
