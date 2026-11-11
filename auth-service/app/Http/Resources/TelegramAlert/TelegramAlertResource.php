<?php

namespace App\Http\Resources\TelegramAlert;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TelegramAlertResource extends JsonResource
{
    /**
     * Transform the resource into an array representation.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => data_get($this->resource, 'id'),
            'tenant_id' => data_get($this->resource, 'tenant_id'),
            'user_id' => data_get($this->resource, 'user_id'),
            'user_name' => data_get($this->resource, 'user_name') ?: (data_get($this->resource, 'user.name') ?: null),
            'meeting_id' => data_get($this->resource, 'meeting_id'),
            'type' => data_get($this->resource, 'type'),
            'title' => data_get($this->resource, 'title'),
            'message' => data_get($this->resource, 'message'),
            'status' => data_get($this->resource, 'status'),
            'created_at' => data_get($this->resource, 'created_at'),
        ];
    }
}

