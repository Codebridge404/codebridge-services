<?php

namespace App\Http\Resources\ApiKey;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApiKeyResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $perms = data_get($this->resource, 'permissions');
        if (is_string($perms)) {
            $perms = json_decode($perms, true);
        }

        return [
            'id' => data_get($this->resource, 'id'),
            'name' => data_get($this->resource, 'name'),
            'key_prefix' => data_get($this->resource, 'key_prefix'),
            'permissions' => is_array($perms) ? $perms : [],
            'last_used_at' => data_get($this->resource, 'last_used_at'),
            'expires_at' => data_get($this->resource, 'expires_at'),
            'is_active' => (bool) data_get($this->resource, 'is_active', true),
            'created_at' => data_get($this->resource, 'created_at'),
        ];
    }
}
