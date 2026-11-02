<?php

namespace App\Http\Resources\Employee;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $firstName = data_get($this->resource, 'first_name', '');
        $lastName = data_get($this->resource, 'last_name', '');
        return [
            'id' => data_get($this->resource, 'id'),
            'employee_code' => data_get($this->resource, 'employee_code'),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'name' => trim("{$firstName} {$lastName}"),
            'full_name' => data_get($this->resource, 'full_name', trim("{$firstName} {$lastName}")),
            'email' => data_get($this->resource, 'email'),
            'phone' => data_get($this->resource, 'phone'),
            'department_id' => data_get($this->resource, 'department_id'),
            'department_name' => data_get($this->resource, 'department_name'),
            'outlet_id' => data_get($this->resource, 'outlet_id'),
            'designation' => data_get($this->resource, 'designation'),
            'employment_type' => data_get($this->resource, 'employment_type'),
            'hire_date' => data_get($this->resource, 'hire_date'),
            'salary' => (float) data_get($this->resource, 'salary', 0),
            'is_active' => (bool) data_get($this->resource, 'is_active', true),
            'created_at' => data_get($this->resource, 'created_at'),
            'updated_at' => data_get($this->resource, 'updated_at'),
        ];
    }
}
