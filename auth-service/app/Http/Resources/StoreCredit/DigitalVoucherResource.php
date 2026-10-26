<?php

namespace App\Http\Resources\StoreCredit;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DigitalVoucherResource extends JsonResource
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
            'voucher_code' => data_get($this->resource, 'voucher_code'),
            'title' => data_get($this->resource, 'title', 'Customer Gift Voucher'),
            'value' => (float) data_get($this->resource, 'value', 0),
            'currency' => data_get($this->resource, 'currency', 'USD'),
            'status' => data_get($this->resource, 'status', 'active'),
            'expires_at' => data_get($this->resource, 'expires_at'),
            'issued_to_customer_id' => data_get($this->resource, 'issued_to_customer_id'),
            'redeemed_at' => data_get($this->resource, 'redeemed_at'),
            'notes' => data_get($this->resource, 'notes'),
            'created_at' => data_get($this->resource, 'created_at'),
        ];
    }
}
