<?php

namespace App\Http\Resources\ApiClient;

use App\Models\ApiClient;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ApiClient
 */
class ApiClientResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array{
     *     id: int,
     *     uuid: string,
     *     name: string,
     *     client_key: string,
     *     client_type: string,
     *     is_active: bool,
     *     user_sessions_count: int|null,
     *     created_at: string,
     *     updated_at: string
     * }
     */
    public function toArray(Request $request): array
    {
        /** @var ApiClient $client */
        $client = $this->resource;

        return [
            'id' => (int) $client->id,
            'uuid' => (string) $client->uuid,
            'name' => (string) $client->name,
            'client_key' => (string) $client->client_key,
            'client_type' => (string) $client->client_type,
            'is_active' => (bool) $client->is_active,
            'user_sessions_count' => isset($client->user_sessions_count) ? (int) $client->user_sessions_count : null,
            'created_at' => $client->created_at?->toIso8601String() ?? (string) $client->created_at,
            'updated_at' => $client->updated_at?->toIso8601String() ?? (string) $client->updated_at,
        ];
    }
}

