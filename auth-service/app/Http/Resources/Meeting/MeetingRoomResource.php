<?php

namespace App\Http\Resources\Meeting;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MeetingRoomResource extends JsonResource
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
            'name' => data_get($this->resource, 'name'),
            'code' => data_get($this->resource, 'code'),
            'floor' => data_get($this->resource, 'floor'),
            'capacity' => (int) data_get($this->resource, 'capacity', 1),
            'amenities' => data_get($this->resource, 'amenities'),
            'status' => data_get($this->resource, 'status', 'active'),
            'is_occupied' => data_get($this->resource, 'is_occupied'),
            'active_meeting' => data_get($this->resource, 'active_meeting'),
            'next_meeting' => data_get($this->resource, 'next_meeting'),
            'created_at' => data_get($this->resource, 'created_at'),
            'updated_at' => data_get($this->resource, 'updated_at'),
        ];
    }
}
