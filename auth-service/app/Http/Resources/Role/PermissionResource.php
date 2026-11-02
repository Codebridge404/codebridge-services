<?php

namespace App\Http\Resources\Role;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PermissionResource extends JsonResource
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
            'name' => data_get($this->resource, 'name'),
            'slug' => data_get($this->resource, 'slug'),
            'module' => data_get($this->resource, 'module'),
            'group' => data_get($this->resource, 'group'),
            'description' => data_get($this->resource, 'description'),
            'is_system_default' => (bool) data_get($this->resource, 'is_system_default', false),
        ];
    }
}
