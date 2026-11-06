<?php

namespace App\Http\Resources\Tenant;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TenantResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array{
     *     id: int,
     *     uuid: string|null,
     *     name: string,
     *     business_type: string,
     *     business_profile: array<string, mixed>|null,
     *     legal_name: string|null,
     *     slug: string,
     *     company_code: string|null,
     *     logo_url: string|null,
     *     receipt_header: string|null,
     *     receipt_footer: string|null,
     *     email: string|null,
     *     phone: string|null,
     *     address: string|null,
     *     country: string|null,
     *     currency: string,
     *     client_tier: string,
     *     status: string,
     *     enabled_modules: list<string>,
     *     payment_settings: array<string, mixed>|null,
     *     max_outlets: int|null,
     *     max_registers: int|null,
     *     max_users: int|null,
     *     outlets_count: int,
     *     users_count: int,
     *     owner: object|null,
     *     subscription: object|null,
     *     subscriptions: list<mixed>,
     *     created_at: string|null,
     *     updated_at: string|null
     * }
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

        $businessType = (string) data_get($this->resource, 'business_type', 'retail_mart');

        $uuid = data_get($this->resource, 'uuid');
        $legalName = data_get($this->resource, 'legal_name');
        $companyCode = data_get($this->resource, 'company_code');
        $logoUrl = data_get($this->resource, 'logo_url');
        $receiptHeader = data_get($this->resource, 'receipt_header');
        $receiptFooter = data_get($this->resource, 'receipt_footer');
        $email = data_get($this->resource, 'email');
        $phone = data_get($this->resource, 'phone');
        $address = data_get($this->resource, 'address');
        $country = data_get($this->resource, 'country');

        $maxOutlets = data_get($this->resource, 'max_outlets');
        $maxRegisters = data_get($this->resource, 'max_registers');
        $maxUsers = data_get($this->resource, 'max_users');

        $createdAt = data_get($this->resource, 'created_at');
        $updatedAt = data_get($this->resource, 'updated_at');

        return [
            'id' => (int) data_get($this->resource, 'id', 1),
            'uuid' => $uuid !== null ? (string) $uuid : null,
            'name' => (string) data_get($this->resource, 'name', 'Sunny Cafe Phnom Penh'),
            'business_type' => $businessType,
            'business_profile' => data_get($this->resource, 'business_profile') ?? (class_exists(\App\Services\PosBusinessProfileService::class) ? \App\Services\PosBusinessProfileService::getProfile($businessType) : null),
            'legal_name' => $legalName !== null ? (string) $legalName : null,
            'slug' => (string) data_get($this->resource, 'slug', 'sunny-cafe-pp'),
            'company_code' => $companyCode !== null ? (string) $companyCode : null,
            'logo_url' => $logoUrl !== null ? (string) $logoUrl : null,
            'receipt_header' => $receiptHeader !== null ? (string) $receiptHeader : null,
            'receipt_footer' => $receiptFooter !== null ? (string) $receiptFooter : null,
            'email' => $email !== null ? (string) $email : null,
            'phone' => $phone !== null ? (string) $phone : null,
            'address' => $address !== null ? (string) $address : null,
            'country' => $country !== null ? (string) $country : null,
            'currency' => (string) data_get($this->resource, 'currency', 'USD'),
            'client_tier' => (string) data_get($this->resource, 'client_tier', 'free_personal'),
            'status' => (string) data_get($this->resource, 'status', 'active'),
            'enabled_modules' => is_array($modules) ? array_values($modules) : [],
            'payment_settings' => is_array($paymentSettings) ? $paymentSettings : null,
            'max_outlets' => $maxOutlets !== null ? (int) $maxOutlets : null,
            'max_registers' => $maxRegisters !== null ? (int) $maxRegisters : null,
            'max_users' => $maxUsers !== null ? (int) $maxUsers : null,
            'outlets_count' => (int) data_get($this->resource, 'outlets_count', 1),
            'users_count' => (int) data_get($this->resource, 'users_count', 1),
            'owner' => data_get($this->resource, 'owner'),
            'subscription' => data_get($this->resource, 'subscription'),
            'subscriptions' => data_get($this->resource, 'subscriptions') ?? (data_get($this->resource, 'subscription') ? [data_get($this->resource, 'subscription')] : []),
            'created_at' => $createdAt !== null ? (string) $createdAt : null,
            'updated_at' => $updatedAt !== null ? (string) $updatedAt : null,
        ];
    }
}