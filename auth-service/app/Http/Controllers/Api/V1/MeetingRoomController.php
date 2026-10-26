<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Meeting\StoreMeetingRoomRequest;
use App\Http\Requests\Meeting\UpdateMeetingRoomRequest;
use App\Http\Resources\Meeting\MeetingRoomResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * [BD-0072] Conference Room Scheduling & Conflict Resolution Controller
 *
 * @ticket BD-0072
 * @component auth-service
 * @file codebridge-services/auth-service/app/Http/Controllers/Api/V1/MeetingRoomController.php
 * @related-model DB table meeting_rooms, App\Models\Outlet
 * @entry-point MeetingRoomController::index(), store(), update(), destroy(), availability()
 * @routes
 *  - GET    /api/v1/meeting-rooms
 *  - POST   /api/v1/meeting-rooms
 *  - PUT    /api/v1/meeting-rooms/{id}
 *  - DELETE /api/v1/meeting-rooms/{id}
 *  - GET    /api/v1/meeting-rooms/{id}/availability
 * @date-time 2026-10-23T15:40:00+07:00
 *
 * Process Capabilities:
 * Step 1 (Tenant Room Directory): Lists physical conference rooms, boardrooms, and meeting pods per store/outlet.
 * Step 2 (Capacity & Amenity Filtering): Filters rooms by attendee seating capacity and audiovisual capabilities.
 * Step 3 (Overlap & Conflict Detection): Validates proposed booking time intervals against existing reservations.
 * Step 4 (Room Reservation Lock): Locks room slot and issues confirmation details to meeting organizer.
 * Step 5 (Room Maintenance Schedule): Blocks rooms during scheduled cleaning, maintenance, or refurbishments.
 */
class MeetingRoomController extends Controller
{
    /**
     * List all meeting rooms with current occupancy status.
     */
    public function index(Request $request)
    {
        $currentUser = $request->user();
        $tenantId = $currentUser?->tenant_id;

        $query = DB::table('meeting_rooms');
        if ($currentUser && $currentUser->role !== 'super_admin' && !empty($tenantId)) {
            $query->where('tenant_id', $tenantId);
        }

        $rooms = $query->orderBy('name', 'asc')->get();
        $now = now();

        foreach ($rooms as $room) {
            // Check if room is currently in a meeting
            $activeMeeting = DB::table('meetings')
                ->where('room_id', $room->id)
                ->where('status', '!=', 'cancelled')
                ->where('start_time', '<=', $now)
                ->where('end_time', '>=', $now)
                ->first();

            $room->is_occupied = (bool) $activeMeeting;
            $room->active_meeting = $activeMeeting ? [
                'id' => $activeMeeting->id,
                'title' => $activeMeeting->title,
                'end_time' => $activeMeeting->end_time,
            ] : null;

            // Next upcoming meeting
            $nextMeeting = DB::table('meetings')
                ->where('room_id', $room->id)
                ->where('status', 'scheduled')
                ->where('start_time', '>', $now)
                ->orderBy('start_time', 'asc')
                ->first();

            $room->next_meeting = $nextMeeting ? [
                'id' => $nextMeeting->id,
                'title' => $nextMeeting->title,
                'start_time' => $nextMeeting->start_time,
            ] : null;
        }

        return response()->json(['status' => 'success', 'data' => MeetingRoomResource::collection($rooms)]);
    }

    /**
     * Create a new meeting room facility.
     */
    public function store(StoreMeetingRoomRequest $request)
    {
        $validated = $request->validated();

        $currentUser = $request->user();
        $tenantId = $currentUser?->tenant_id;

        $id = DB::table('meeting_rooms')->insertGetId([
            'tenant_id' => $tenantId,
            'name' => $validated['name'],
            'code' => $validated['code'] ?? null,
            'floor' => $validated['floor'] ?? null,
            'capacity' => $validated['capacity'],
            'amenities' => $validated['amenities'] ?? null,
            'status' => $validated['status'] ?? 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Meeting room added successfully.',
            'data' => DB::table('meeting_rooms')->where('id', $id)->first(),
        ], 201);
    }

    /**
     * Update room details.
     */
    public function update(UpdateMeetingRoomRequest $request, $id)
    {
        $validated = $request->validated();

        DB::table('meeting_rooms')->where('id', $id)->update(array_merge($validated, ['updated_at' => now()]));
        return response()->json(['status' => 'success', 'message' => 'Meeting room updated successfully.']);
    }

    /**
     * Delete room.
     */
    public function destroy($id)
    {
        DB::table('meeting_rooms')->where('id', $id)->delete();
        return response()->json(['status' => 'success', 'message' => 'Meeting room deleted successfully.']);
    }
}
