<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MeetingIssueController extends Controller
{
    /**
     * List all facility issues with optional room_id or status filters.
     * GET /api/v1/meetings/issues
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $tenantId = $user?->tenant_id;
        $roomId = $request->query('room_id');
        $status = $request->query('status');

        $query = DB::table('meeting_room_issues as i')
            ->leftJoin('meeting_rooms as r', 'i.room_id', '=', 'r.id')
            ->leftJoin('users as u', 'i.reported_by', '=', 'u.id');

        if ($user && $user->role !== 'super_admin' && !empty($tenantId)) {
            $query->where(function ($q) use ($tenantId) {
                $q->where('i.tenant_id', $tenantId)->orWhereNull('i.tenant_id');
            });
        }

        if (!empty($roomId)) {
            $query->where('i.room_id', $roomId);
        }
        if (!empty($status)) {
            $query->where('i.status', $status);
        }

        $issues = $query->select(
            'i.*',
            'r.name as room_name',
            'r.floor as room_floor',
            'u.name as reporter_name',
            'u.email as reporter_email'
        )->orderBy('i.created_at', 'desc')->get();

        return response()->json(['status' => 'success', 'data' => $issues]);
    }

    /**
     * Report an in-room equipment or facility issue (Accessible from Tablet Kiosks or Web).
     * POST /api/v1/meetings/rooms/{roomId}/issues
     */
    public function report(Request $request, $roomId)
    {
        $validated = $request->validate([
            'category' => 'required|string|in:audio_visual,air_conditioning,cleanliness,network,furniture',
            'priority' => 'nullable|string|in:low,medium,high,critical',
            'description' => 'required|string|max:1000',
        ]);

        $user = auth('sanctum')->user() ?? $request->user();
        $room = DB::table('meeting_rooms')->where('id', $roomId)->first();
        if (!$room) {
            return response()->json(['status' => 'error', 'message' => 'Meeting room not found.'], 404);
        }

        $id = DB::table('meeting_room_issues')->insertGetId([
            'tenant_id' => $room->tenant_id ?? $user?->tenant_id,
            'room_id' => $roomId,
            'reported_by' => $user?->id ?? null,
            'category' => $validated['category'],
            'priority' => $validated['priority'] ?? 'medium',
            'status' => 'reported',
            'description' => $validated['description'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $issue = DB::table('meeting_room_issues')->where('id', $id)->first();
        return response()->json([
            'status' => 'success',
            'message' => 'Facility defect reported successfully. Our support team has been alerted.',
            'data' => $issue,
        ], 201);
    }

    /**
     * Update issue status and resolution notes.
     * PATCH /api/v1/meetings/issues/{id}/status
     */
    public function updateStatus(Request $request, $id)
    {
        $validated = $request->validate([
            'status' => 'required|string|in:reported,under_inspection,resolved,cancelled',
            'resolution_notes' => 'nullable|string',
        ]);

        $updateData = [
            'status' => $validated['status'],
            'resolution_notes' => $validated['resolution_notes'] ?? null,
            'updated_at' => now(),
        ];

        if ($validated['status'] === 'resolved') {
            $updateData['resolved_at'] = now();
        }

        DB::table('meeting_room_issues')->where('id', $id)->update($updateData);

        $fresh = DB::table('meeting_room_issues')->where('id', $id)->first();
        return response()->json(['status' => 'success', 'message' => 'Issue status updated.', 'data' => $fresh]);
    }
}
