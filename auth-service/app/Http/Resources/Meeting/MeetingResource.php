<?php

namespace App\Http\Resources\Meeting;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MeetingResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $attendees = data_get($this->resource, 'attendees');
        if (is_string($attendees)) {
            $attendees = json_decode($attendees, true);
        }

        return [
            'id' => data_get($this->resource, 'id'),
            'room_id' => data_get($this->resource, 'room_id'),
            'room_name' => data_get($this->resource, 'room_name'),
            'organizer_id' => data_get($this->resource, 'organizer_id'),
            'organizer_name' => data_get($this->resource, 'organizer_name'),
            'title' => data_get($this->resource, 'title'),
            'description' => data_get($this->resource, 'description'),
            'status' => data_get($this->resource, 'status', 'scheduled'),
            'start_time' => data_get($this->resource, 'start_time'),
            'end_time' => data_get($this->resource, 'end_time'),
            'meeting_type' => data_get($this->resource, 'meeting_type'),
            'meeting_link' => data_get($this->resource, 'meeting_link'),
            'attendees' => is_array($attendees) ? $attendees : [],
            'attendees_count' => data_get($this->resource, 'attendees_count'),
            'materials_count' => data_get($this->resource, 'materials_count'),
            'staff_count' => data_get($this->resource, 'staff_count'),
            'has_minutes' => data_get($this->resource, 'has_minutes'),
            'created_at' => data_get($this->resource, 'created_at'),
            'updated_at' => data_get($this->resource, 'updated_at'),
        ];
    }
}
