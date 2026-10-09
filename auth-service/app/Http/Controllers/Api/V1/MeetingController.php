<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MeetingController extends Controller
{
    /**
     * List meetings for the workspace with optional date/room filters.
     */
    public function index(Request $request)
    {
        $currentUser = $request->user();
        $tenantId = $currentUser?->tenant_id;
        $date = $request->query('date');
        $roomId = $request->query('room_id');
        $status = $request->query('status');

        $query = DB::table('meetings as m')
            ->leftJoin('meeting_rooms as r', 'm.room_id', '=', 'r.id')
            ->leftJoin('users as u', 'm.organizer_id', '=', 'u.id');

        if ($currentUser && $currentUser->role !== 'super_admin' && !empty($tenantId)) {
            $query->where('m.tenant_id', $tenantId);
        }
        if (!empty($date)) {
            $query->whereDate('m.start_time', $date);
        }
        if (!empty($roomId)) {
            $query->where('m.room_id', $roomId);
        }
        if (!empty($status)) {
            $query->where('m.status', $status);
        }

        $meetings = $query->select(
            'm.*',
            'r.name as room_name', 'r.floor as room_floor', 'r.capacity as room_capacity',
            'u.name as organizer_name', 'u.email as organizer_email'
        )->orderBy('m.start_time', 'asc')->get();

        // Attach attendees count
        foreach ($meetings as $meeting) {
            $meeting->attendees_count = DB::table('meeting_attendees')->where('meeting_id', $meeting->id)->count();
            $meeting->has_minutes = DB::table('meeting_minutes')->where('meeting_id', $meeting->id)->exists();
        }

        return response()->json(['status' => 'success', 'data' => $meetings]);
    }

    /**
     * Create a new meeting with Zero-Conflict time interval checking.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'room_id' => 'required|integer',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'start_time' => 'required|date',
            'end_time' => 'required|date|after:start_time',
            'meeting_type' => 'nullable|string',
            'meeting_link' => 'nullable|string',
            'attendees' => 'nullable|array',
        ]);

        $currentUser = $request->user();
        $tenantId = $currentUser?->tenant_id;

        // Zero-conflict validation: ensure no overlapping meeting in this room
        $conflict = DB::table('meetings')
            ->where('room_id', $validated['room_id'])
            ->where('status', '!=', 'cancelled')
            ->where(function ($q) use ($validated) {
                $q->whereBetween('start_time', [$validated['start_time'], $validated['end_time']])
                  ->orWhereBetween('end_time', [$validated['start_time'], $validated['end_time']])
                  ->orWhere(function ($sub) use ($validated) {
                      $sub->where('start_time', '<=', $validated['start_time'])
                          ->where('end_time', '>=', $validated['end_time']);
                  });
            })->first();

        if ($conflict) {
            return response()->json([
                'status' => 'error',
                'code' => 'SCHEDULE_CONFLICT',
                'message' => 'The selected room is already booked during this time interval.',
                'conflict' => $conflict,
            ], 422);
        }

        $meetingId = DB::table('meetings')->insertGetId([
            'tenant_id' => $tenantId,
            'room_id' => $validated['room_id'],
            'organizer_id' => $currentUser->id,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'start_time' => $validated['start_time'],
            'end_time' => $validated['end_time'],
            'status' => 'scheduled',
            'meeting_type' => $validated['meeting_type'] ?? 'internal',
            'meeting_link' => $validated['meeting_link'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Insert organizer attendee
        DB::table('meeting_attendees')->insert([
            'meeting_id' => $meetingId,
            'user_id' => $currentUser->id,
            'name' => $currentUser->name,
            'email' => $currentUser->email,
            'role' => 'organizer',
            'rsvp_status' => 'accepted',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Insert additional attendees
        if (!empty($validated['attendees']) && is_array($validated['attendees'])) {
            foreach ($validated['attendees'] as $att) {
                DB::table('meeting_attendees')->insert([
                    'meeting_id' => $meetingId,
                    'user_id' => $att['user_id'] ?? null,
                    'name' => $att['name'] ?? 'Guest Attendee',
                    'email' => $att['email'] ?? null,
                    'role' => $att['role'] ?? 'attendee',
                    'rsvp_status' => 'pending',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Meeting scheduled successfully.',
            'data' => ['id' => $meetingId],
        ], 201);
    }

    /**
     * View single meeting details, attendees, and minutes.
     */
    public function show($id)
    {
        $meeting = DB::table('meetings as m')
            ->leftJoin('meeting_rooms as r', 'm.room_id', '=', 'r.id')
            ->leftJoin('users as u', 'm.organizer_id', '=', 'u.id')
            ->where('m.id', $id)
            ->select('m.*', 'r.name as room_name', 'r.floor as room_floor', 'u.name as organizer_name', 'u.email as organizer_email')
            ->first();

        if (!$meeting) {
            return response()->json(['status' => 'error', 'message' => 'Meeting not found.'], 404);
        }

        $attendees = DB::table('meeting_attendees')->where('meeting_id', $id)->get();
        $minutes = DB::table('meeting_minutes')->where('meeting_id', $id)->first();

        return response()->json([
            'status' => 'success',
            'data' => [
                'meeting' => $meeting,
                'attendees' => $attendees,
                'minutes' => $minutes,
            ],
        ]);
    }

    /**
     * Reschedule or update meeting.
     */
    public function update(Request $request, $id)
    {
        $meeting = DB::table('meetings')->where('id', $id)->first();
        if (!$meeting) {
            return response()->json(['status' => 'error', 'message' => 'Meeting not found.'], 404);
        }

        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'description' => 'sometimes|nullable|string',
            'room_id' => 'sometimes|integer',
            'start_time' => 'sometimes|date',
            'end_time' => 'sometimes|date',
            'status' => 'sometimes|string',
            'meeting_link' => 'sometimes|nullable|string',
        ]);

        $updateData = array_merge($validated, ['updated_at' => now()]);
        DB::table('meetings')->where('id', $id)->update($updateData);

        return response()->json(['status' => 'success', 'message' => 'Meeting updated successfully.']);
    }

    /**
     * Cancel or delete a meeting.
     */
    public function destroy($id)
    {
        DB::table('meetings')->where('id', $id)->update(['status' => 'cancelled', 'updated_at' => now()]);
        return response()->json(['status' => 'success', 'message' => 'Meeting cancelled successfully.']);
    }

    /**
     * Dashboard statistics for MMS.
     */
    public function stats(Request $request)
    {
        $currentUser = $request->user();
        $tenantId = $currentUser?->tenant_id;

        $mQuery = DB::table('meetings');
        $rQuery = DB::table('meeting_rooms');

        if ($currentUser && $currentUser->role !== 'super_admin' && !empty($tenantId)) {
            $mQuery->where('tenant_id', $tenantId);
            $rQuery->where('tenant_id', $tenantId);
        }

        $today = now()->toDateString();
        $totalToday = (clone $mQuery)->whereDate('start_time', $today)->count();
        $upcoming = (clone $mQuery)->where('start_time', '>=', now())->where('status', 'scheduled')->count();
        $completed = (clone $mQuery)->where('status', 'completed')->count();
        $totalRooms = $rQuery->count();

        return response()->json([
            'status' => 'success',
            'data' => [
                'today_count' => $totalToday,
                'upcoming_count' => $upcoming,
                'completed_count' => $completed,
                'total_rooms' => $totalRooms,
            ],
        ]);
    }

    /**
     * Check-in to room to prevent ghost room auto-release.
     */
    public function checkIn($id)
    {
        $meeting = DB::table('meetings')->where('id', $id)->first();
        if (!$meeting) {
            return response()->json(['status' => 'error', 'message' => 'Meeting not found.'], 404);
        }

        DB::table('meetings')->where('id', $id)->update([
            'status' => 'in_progress',
            'updated_at' => now(),
        ]);

        return response()->json(['status' => 'success', 'message' => 'Checked in successfully. Room is marked active.']);
    }

    /**
     * Export RFC 5545 standard .ics calendar invite.
     */
    public function exportIcs($id)
    {
        $m = DB::table('meetings as m')
            ->leftJoin('meeting_rooms as r', 'm.room_id', '=', 'r.id')
            ->where('m.id', $id)
            ->select('m.*', 'r.name as room_name', 'r.floor as room_floor')
            ->first();

        if (!$m) {
            return response()->json(['status' => 'error', 'message' => 'Meeting not found.'], 404);
        }

        $dtStart = gmdate('Ymd\THis\Z', strtotime($m->start_time));
        $dtEnd = gmdate('Ymd\THis\Z', strtotime($m->end_time));
        $location = trim(($m->room_name ?? 'Conference Room') . ' (' . ($m->room_floor ?? 'Main Floor') . ')');
        $desc = str_replace(["\r\n", "\n"], "\\n", $m->description ?? 'CodeBridges MMS Meeting');

        $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//CodeBridges Enterprise MMS//EN\r\nBEGIN:VEVENT\r\n" .
            "UID:mms-{$m->id}@codebridges.io\r\nDTSTAMP:" . gmdate('Ymd\THis\Z') . "\r\n" .
            "DTSTART:{$dtStart}\r\nDTEND:{$dtEnd}\r\n" .
            "SUMMARY:{$m->title}\r\nDESCRIPTION:{$desc}\r\n" .
            "LOCATION:{$location}\r\nSTATUS:CONFIRMED\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

        return response($ics, 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="meeting-' . $m->id . '.ics"',
        ]);
    }
}

