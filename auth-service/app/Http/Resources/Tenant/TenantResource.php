<?php

namespace App\Http\Resources\Tenant;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TenantResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $modules = data_get($this->resource, 'enabled_modules');
        if (is_string($modules)) {
            $modules = json_decode($modules, true);
        }

        $paymentSettings = data_get($this->resource, 'payment_settings');
        if (is_string($paymentSettings)) {
            $paymentSettings = json_decode($paymentSettings, true);
        }

        $businessType = data_get($this->resource, 'business_type', 'retail_mart');

        return [
            'id' => data_get($this->resource, 'id'),
            'uuid' => data_get($this->resource, 'uuid'),
            'name' => data_get($this->resource, 'name'),
            'business_type' => $businessType,
            'business_profile' => data_get($this->resource, 'business_profile') ?? (class_exists(\App\Services\PosBusinessProfileService::class) ? \App\Services\PosBusinessProfileService::getProfile($businessType) : null),
            'legal_name' => data_get($this->resource, 'legal_name'),
            'slug' => data_get($this->resource, 'slug'),
            'company_code' => data_get($this->resource, 'company_code'),
            'logo_url' => data_get($this->resource, 'logo_url'),
            'receipt_header' => data_get($this->resource, 'receipt_header'),
            'receipt_footer' => data_get($this->resource, 'receipt_footer'),
            'email' => data_get($this->resource, 'email'),
            'phone' => data_get($this->resource, 'phone'),
            'address' => data_get($this->resource, 'address'),
            'country' => data_get($this->resource, 'country'),
            'currency' => data_get($this->resource, 'currency'),
            'client_tier' => data_get($this->resource, 'client_tier'),
            'status' => data_get($this->resource, 'status', 'active'),
            'enabled_modules' => is_array($modules) ? array_values($modules) : [],
            'payment_settings' => is_array($paymentSettings) ? $paymentSettings : null,
            'max_outlets' => data_get($this->resource, 'max_outlets') ? (int) data_get($this->resource, 'max_outlets') : null,
            'max_registers' => data_get($this->resource, 'max_registers') ? (int) data_get($this->resource, 'max_registers') : null,
            'max_users' => data_get($this->resource, 'max_users') ? (int) data_get($this->resource, 'max_users') : null,
            'outlets_count' => data_get($this->resource, 'outlets_count'),
            'subscriptions' => data_get($this->resource, 'subscriptions'),
            'created_at' => data_get($this->resource, 'created_at'),
            'updated_at' => data_get($this->resource, 'updated_at'),
        ];
    }
}