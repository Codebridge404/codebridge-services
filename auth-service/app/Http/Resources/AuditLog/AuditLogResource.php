<?php

namespace App\Http\Resources\AuditLog;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuditLogResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $payload = data_get($this->resource, 'payload');
        if (is_string($payload)) {
            $payload = json_decode($payload, true);
        }

        return [
            'id' => data_get($this->resource, 'id'),
            'user_id' => data_get($this->resource, 'user_id'),
            'user_name' => data_get($this->resource, 'user_name') ?: 'System / Anonymous',
            'action' => data_get($this->resource, 'action') ?: 'system.event',
            'module' => data_get($this->resource, 'module') ?: 'system',
            'ip_address' => data_get($this->resource, 'ip_address') ?: '127.0.0.1',
            'payload' => is_array($payload) ? $payload : null,
            'created_at' => data_get($this->resource, 'created_at'),
        ];
    }
}
