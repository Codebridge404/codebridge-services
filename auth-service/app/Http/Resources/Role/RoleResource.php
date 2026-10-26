<?php

namespace App\Http\Resources\Role;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoleResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $permIds = data_get($this->resource, 'permission_ids', []);
        return [
            'id' => data_get($this->resource, 'id'),
            'uuid' => data_get($this->resource, 'uuid'),
            'name' => data_get($this->resource, 'name'),
            'slug' => data_get($this->resource, 'slug'),
            'description' => data_get($this->resource, 'description'),
            'parent_role' => data_get($this->resource, 'parent_role'),
            'is_system' => (bool) data_get($this->resource, 'is_system', false),
            'is_active' => (bool) data_get($this->resource, 'is_active', true),
            'company_id' => data_get($this->resource, 'company_id'),
            'permission_ids' => is_array($permIds) ? $permIds : [],
            'permissions_count' => data_get($this->resource, 'permissions_count', is_array($permIds) ? count($permIds) : 0),
            'created_at' => data_get($this->resource, 'created_at'),
            'updated_at' => data_get($this->resource, 'updated_at'),
        ];
    }
}
