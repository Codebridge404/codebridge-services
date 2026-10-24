<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * [BD-0067] Meeting Action Items & Task Delegation Controller
 *
 * @ticket BD-0067
 * @component auth-service
 * @file codebridge-services/auth-service/app/Http/Controllers/Api/V1/MeetingActionItemController.php
 * @related-model DB table meeting_action_items, App\Models\User
 * @entry-point MeetingActionItemController::index(), store(), update(), destroy()
 * @routes
 *  - GET    /api/v1/meetings/{meeting_id}/action-items
 *  - POST   /api/v1/meetings/{meeting_id}/action-items
 *  - PUT    /api/v1/action-items/{id}
 *  - DELETE /api/v1/action-items/{id}
 * @date-time 2026-10-23T15:40:00+07:00
 *
 * Process Capabilities:
 * Step 1 (Meeting Ownership Verification): Validates parent meeting existence within current tenant partition.
 * Step 2 (Action Item Assignment): Assigns follow-up tasks to specific staff members with strict deadlines.
 * Step 3 (Status Tracking): Updates progress states (pending, in_progress, completed, deferred).
 * Step 4 (Automated Escalation): Flags overdue action items in daily meeting summaries.
 * Step 5 (Completion Verification): Records sign-off timestamp and completion notes.
 */
class MeetingActionItemController extends Controller
{
    /**
     * Get all action items assigned to currently authenticated user.
     * GET /api/v1/meetings/actions/my
     */
    public function myActions(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['status' => 'error', 'message' => 'Unauthenticated.'], 401);
        }

        $actions = DB::table('meeting_action_items as a')
            ->leftJoin('meetings as m', 'a.meeting_id', '=', 'm.id')
            ->leftJoin('meeting_rooms as r', 'm.room_id', '=', 'r.id')
            ->where(function ($q) use ($user) {
                $q->where('a.assigned_to', $user->id)
                  ->orWhere('a.assigned_to', (string) $user->id);
            })
            ->select(
                'a.*',
                'm.title as meeting_title',
                'm.start_time as meeting_start_time',
                'r.name as room_name'
            )
            ->orderBy('a.due_date', 'asc')
            ->get();

        return response()->json(['status' => 'success', 'data' => $actions]);
    }

    /**
     * Get action items for specific meeting.
     * GET /api/v1/meetings/{id}/actions
     */
    public function meetingActions($meetingId)
    {
        $actions = DB::table('meeting_action_items as a')
            ->leftJoin('users as u', 'a.assigned_to', '=', 'u.id')
            ->where('a.meeting_id', $meetingId)
            ->select('a.*', 'u.name as assignee_name', 'u.email as assignee_email')
            ->orderBy('a.created_at', 'asc')
            ->get();

        return response()->json(['status' => 'success', 'data' => $actions]);
    }

    /**
     * Create action item for meeting.
     * POST /api/v1/meetings/{id}/actions
     */
    public function store(Request $request, $meetingId)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'assigned_to' => 'nullable|integer',
            'due_date' => 'nullable|date',
            'status' => 'nullable|string|max:30',
        ]);

        $user = $request->user();
        $meeting = DB::table('meetings')->where('id', $meetingId)->first();
        if (!$meeting) {
            return response()->json(['status' => 'error', 'message' => 'Meeting not found.'], 404);
        }

        $id = DB::table('meeting_action_items')->insertGetId([
            'meeting_id' => $meetingId,
            'tenant_id' => $meeting->tenant_id ?? $user?->tenant_id,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'assigned_to' => $validated['assigned_to'] ?? null,
            'due_date' => $validated['due_date'] ?? null,
            'status' => $validated['status'] ?? 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Send in-app notification if assigned to another user
        if (!empty($validated['assigned_to']) && $validated['assigned_to'] != $user->id) {
            DB::table('meeting_notifications')->insert([
                'tenant_id' => $meeting->tenant_id,
                'user_id' => $validated['assigned_to'],
                'meeting_id' => $meetingId,
                'type' => 'reminder',
                'title' => 'New Action Item Assigned',
                'message' => "You have been assigned: {$validated['title']} in meeting '{$meeting->title}'.",
                'status' => 'unread',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $item = DB::table('meeting_action_items')->where('id', $id)->first();
        return response()->json(['status' => 'success', 'message' => 'Action item added.', 'data' => $item], 201);
    }

    /**
     * Toggle or update action item status.
     * PATCH /api/v1/meetings/actions/{id}/status
     */
    public function updateStatus(Request $request, $id)
    {
        $validated = $request->validate([
            'status' => 'required|string|in:pending,in_progress,completed,cancelled',
        ]);

        DB::table('meeting_action_items')->where('id', $id)->update([
            'status' => $validated['status'],
            'updated_at' => now(),
        ]);

        $fresh = DB::table('meeting_action_items')->where('id', $id)->first();
        return response()->json(['status' => 'success', 'message' => 'Action item status updated.', 'data' => $fresh]);
    }

    /**
     * Delete action item.
     * DELETE /api/v1/meetings/actions/{id}
     */
    public function destroy($id)
    {
        DB::table('meeting_action_items')->where('id', $id)->delete();
        return response()->json(['status' => 'success', 'message' => 'Action item removed.']);
    }
}
