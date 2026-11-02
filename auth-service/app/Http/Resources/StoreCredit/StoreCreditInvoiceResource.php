<?php

namespace App\Http\Resources\StoreCredit;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StoreCreditInvoiceResource extends JsonResource
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
            'invoice_number' => data_get($this->resource, 'invoice_number'),
            'customer_id' => data_get($this->resource, 'customer_id'),
            'customer_name' => data_get($this->resource, 'customer_name'),
            'amount_paid' => (float) data_get($this->resource, 'amount_paid', 0),
            'credit_amount' => (float) data_get($this->resource, 'credit_amount', 0),
            'bonus_credit' => (float) data_get($this->resource, 'bonus_credit', 0),
            'payment_method' => data_get($this->resource, 'payment_method', 'cash'),
            'payment_reference' => data_get($this->resource, 'payment_reference'),
            'status' => data_get($this->resource, 'status', 'active'),
            'expires_at' => data_get($this->resource, 'expires_at'),
            'notes' => data_get($this->resource, 'notes'),
            'created_at' => data_get($this->resource, 'created_at'),
            'updated_at' => data_get($this->resource, 'updated_at'),
        ];
    }
}
