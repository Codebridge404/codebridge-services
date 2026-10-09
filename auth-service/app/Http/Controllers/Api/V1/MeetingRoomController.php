<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

        return response()->json(['status' => 'success', 'data' => $rooms]);
    }

    /**
     * Create a new meeting room facility.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:150',
            'code' => 'nullable|string|max:50',
            'floor' => 'nullable|string|max:50',
            'capacity' => 'required|integer|min:1',
            'amenities' => 'nullable|string',
            'status' => 'nullable|string',
        ]);

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
    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:150',
            'code' => 'sometimes|nullable|string|max:50',
            'floor' => 'sometimes|nullable|string|max:50',
            'capacity' => 'sometimes|integer|min:1',
            'amenities' => 'sometimes|nullable|string',
            'status' => 'sometimes|string',
        ]);

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
