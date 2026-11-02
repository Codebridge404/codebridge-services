<?php

namespace App\Http\Resources\Department;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DepartmentResource extends JsonResource
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
            'code' => data_get($this->resource, 'code'),
            'name' => data_get($this->resource, 'name'),
            'description' => data_get($this->resource, 'description'),
            'employee_count' => (int) data_get($this->resource, 'employee_count', 0),
            'employees_count' => data_get($this->resource, 'employees_count') !== null ? (int) data_get($this->resource, 'employees_count') : (int) data_get($this->resource, 'employee_count', 0),
            'is_active' => (bool) data_get($this->resource, 'is_active', true),
            'created_at' => data_get($this->resource, 'created_at'),
            'updated_at' => data_get($this->resource, 'updated_at'),
        ];
    }
}
