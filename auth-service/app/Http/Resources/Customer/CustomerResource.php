<?php

namespace App\Http\Resources\Customer;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerResource extends JsonResource
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
            'code' => data_get($this->resource, 'code'),
            'name' => data_get($this->resource, 'name'),
            'phone' => data_get($this->resource, 'phone'),
            'email' => data_get($this->resource, 'email'),
            'address' => data_get($this->resource, 'address'),
            'store_credit' => (float) data_get($this->resource, 'store_credit', 0),
            'store_credit_balance' => (float) (data_get($this->resource, 'store_credit_balance') ?? data_get($this->resource, 'store_credit', 0)),
            'loyalty_points' => (int) data_get($this->resource, 'loyalty_points', 0),
            'total_spent' => (float) data_get($this->resource, 'total_spent', 0),
            'is_active' => (bool) data_get($this->resource, 'is_active', true),
            'created_at' => data_get($this->resource, 'created_at'),
            'updated_at' => data_get($this->resource, 'updated_at'),
        ];
    }
}
