<?php

namespace App\Http\Resources\Outlet;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OutletResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $terminalSettings = data_get($this->resource, 'terminal_settings');
        if (is_string($terminalSettings)) {
            $terminalSettings = json_decode($terminalSettings, true);
        }

        return [
            'id' => data_get($this->resource, 'id'),
            'uuid' => data_get($this->resource, 'uuid'),
            'tenant_id' => data_get($this->resource, 'tenant_id'),
            'name' => data_get($this->resource, 'name'),
            'code' => data_get($this->resource, 'code'),
            'business_type' => data_get($this->resource, 'business_type'),
            'address' => data_get($this->resource, 'address'),
            'phone' => data_get($this->resource, 'phone'),
            'email' => data_get($this->resource, 'email'),
            'is_active' => (bool) data_get($this->resource, 'is_active', true),
            'receipt_header' => data_get($this->resource, 'receipt_header'),
            'receipt_footer' => data_get($this->resource, 'receipt_footer'),
            'is_kds_enabled' => (bool) data_get($this->resource, 'is_kds_enabled', false),
            'is_cfd_enabled' => (bool) data_get($this->resource, 'is_cfd_enabled', false),
            'terminal_settings' => is_array($terminalSettings) ? $terminalSettings : null,
            'staff_count' => data_get($this->resource, 'staff_count') !== null ? (int) data_get($this->resource, 'staff_count') : null,
            'registers_count' => data_get($this->resource, 'registers_count') !== null ? (int) data_get($this->resource, 'registers_count') : null,
            'created_at' => data_get($this->resource, 'created_at'),
            'updated_at' => data_get($this->resource, 'updated_at'),
        ];
    }
}
