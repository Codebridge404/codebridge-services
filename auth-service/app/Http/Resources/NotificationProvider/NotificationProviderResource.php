<?php

namespace App\Http\Resources\NotificationProvider;

use App\Models\NotificationProvider;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin NotificationProvider
 */
class NotificationProviderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array{
     *     id: int,
     *     uuid: string,
     *     tenant_id: int|null,
     *     tenant_name: string|null,
     *     name: string,
     *     channel: string,
     *     provider: string,
     *     environment: string,
     *     masked_credentials: array<string, mixed>,
     *     settings: array<string, mixed>,
     *     is_enabled: bool,
     *     config_version: int,
     *     created_by: int|null,
     *     created_by_name: string|null,
     *     updated_by: int|null,
     *     updated_by_name: string|null,
     *     last_tested_at: string|null,
     *     created_at: string,
     *     updated_at: string
     * }
     */
    public function toArray(Request $request): array
    {
        /** @var NotificationProvider $provider */
        $provider = $this->resource;

        $maskedCreds = method_exists($provider, 'getMaskedCredentials')
            ? $provider->getMaskedCredentials()
            : [];

        $settings = is_array($provider->settings) ? $provider->settings : [];

        return [
            'id' => (int) $provider->id,
            'uuid' => (string) $provider->uuid,
            'tenant_id' => $provider->tenant_id ? (int) $provider->tenant_id : null,
            'tenant_name' => $provider->tenant?->name ?? null,
            'name' => (string) $provider->name,
            'channel' => (string) $provider->channel,
            'provider' => (string) $provider->provider,
            'environment' => (string) $provider->environment,
            'masked_credentials' => $maskedCreds,
            'settings' => $settings,
            'is_enabled' => (bool) $provider->is_enabled,
            'config_version' => (int) ($provider->config_version ?? 1),
            'created_by' => $provider->created_by ? (int) $provider->created_by : null,
            'created_by_name' => $provider->creator?->name ?? null,
            'updated_by' => $provider->updated_by ? (int) $provider->updated_by : null,
            'updated_by_name' => $provider->updater?->name ?? null,
            'last_tested_at' => $provider->last_tested_at?->toIso8601String() ?? ($provider->last_tested_at ? (string) $provider->last_tested_at : null),
            'created_at' => $provider->created_at?->toIso8601String() ?? (string) $provider->created_at,
            'updated_at' => $provider->updated_at?->toIso8601String() ?? (string) $provider->updated_at,
        ];
    }
}
