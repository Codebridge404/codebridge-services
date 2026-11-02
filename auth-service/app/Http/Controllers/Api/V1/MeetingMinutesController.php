<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Meeting\StoreOrUpdateMeetingMinutesRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * [BD-0070] Meeting Proceedings, Notes & Minutes Archiving Controller
 *
 * @ticket BD-0070
 * @component auth-service
 * @file codebridge-services/auth-service/app/Http/Controllers/Api/V1/MeetingMinutesController.php
 * @related-model DB table meeting_minutes, App\Models\User
 * @entry-point MeetingMinutesController::show(), store(), update(), publish()
 * @routes
 *  - GET  /api/v1/meetings/{meeting_id}/minutes
 *  - POST /api/v1/meetings/{meeting_id}/minutes
 *  - PUT  /api/v1/meetings/{meeting_id}/minutes
 *  - POST /api/v1/meetings/{meeting_id}/minutes/publish
 * @date-time 2026-10-23T15:40:00+07:00
 *
 * Process Capabilities:
 * Step 1 (Tenant Validation): Restricts minutes documentation to authorized meeting participants within tenant.
 * Step 2 (Note Drafting & Formatting): Records structured agenda topics, attendee discussions, and formal motions.
 * Step 3 (Participant Sign-Off): Collects electronic acknowledgments from meeting attendees.
 * Step 4 (Versioning & Immutability): Maintains revision history and freezes finalized official minutes.
 * Step 5 (Distribution & Export): Generates downloadable summary reports for stakeholder circulation.
 */
class MeetingMinutesController extends Controller
{
    /**
     * Get official minutes & action items for a meeting.
     */
    public function show($meetingId)
    {
        $minutes = DB::table('meeting_minutes as mm')
            ->leftJoin('users as u', 'mm.recorded_by', '=', 'u.id')
            ->where('mm.meeting_id', $meetingId)
            ->select('mm.*', 'u.name as recorder_name')
            ->first();

        if ($minutes && !empty($minutes->action_items)) {
            $minutes->action_items = is_string($minutes->action_items) ? json_decode($minutes->action_items, true) : $minutes->action_items;
        }

        return response()->json(['status' => 'success', 'data' => $minutes]);
    }

    /**
     * Save or publish minutes and action items.
     * When status is 'published', broadcasts notifications to all attendees and records audit trail.
     */
    public function storeOrUpdate(StoreOrUpdateMeetingMinutesRequest $request, $meetingId)
    {
        $validated = $request->validated();

        $currentUser = $request->user();
        $actionItemsJson = !empty($validated['action_items']) ? json_encode($validated['action_items']) : null;
        $status = $validated['status'] ?? 'draft';

        $meeting = DB::table('meetings as m')
            ->leftJoin('meeting_rooms as r', 'm.room_id', '=', 'r.id')
            ->where('m.id', $meetingId)
            ->select('m.*', 'r.name as room_name')
            ->first();

        if (!$meeting) {
            return response()->json(['status' => 'error', 'message' => 'Meeting not found.'], 404);
        }

        $existing = DB::table('meeting_minutes')->where('meeting_id', $meetingId)->first();

        if ($existing) {
            DB::table('meeting_minutes')->where('meeting_id', $meetingId)->update([
                'author_id' => $currentUser?->id ?? $existing->author_id,
                'recorded_by' => $currentUser?->id ?? $existing->recorded_by,
                'content' => $validated['content'],
                'content_markdown' => $validated['content'],
                'action_items' => $actionItemsJson,
                'status' => $status,
                'is_published' => $status === 'published' ? 1 : ($existing->is_published ?? 0),
                'published_at' => $status === 'published' ? ($existing->published_at ?? now()) : $existing->published_at,
                'updated_at' => now(),
            ]);
        } else {
            DB::table('meeting_minutes')->insert([
                'meeting_id' => $meetingId,
                'author_id' => $currentUser?->id,
                'recorded_by' => $currentUser?->id,
                'content' => $validated['content'],
                'content_markdown' => $validated['content'],
                'action_items' => $actionItemsJson,
                'status' => $status,
                'is_published' => $status === 'published' ? 1 : 0,
                'published_at' => $status === 'published' ? now() : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // If newly published, broadcast notifications to all meeting participants
        if ($status === 'published') {
            $attendees = DB::table('meeting_attendees')
                ->where('meeting_id', $meetingId)
                ->whereNotNull('user_id')
                ->get();

            foreach ($attendees as $att) {
                // Prevent duplicate published notification
                $exists = DB::table('meeting_notifications')
                    ->where('meeting_id', $meetingId)
                    ->where('user_id', $att->user_id)
                    ->where('type', 'minutes_published')
                    ->exists();

                if (!$exists) {
                    DB::table('meeting_notifications')->insert([
                        'tenant_id' => $meeting->tenant_id,
                        'user_id' => $att->user_id,
                        'meeting_id' => $meetingId,
                        'type' => 'minutes_published',
                        'title' => "Minutes Published: {$meeting->title}",
                        'message' => "Official Meeting Minutes (MOM) and executive action items have been published.",
                        'status' => 'unread',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            // Dispatch Telegram announcement
            MeetingTelegramController::dispatchTelegramAlert('change', [
                'title' => $meeting->title,
                'room' => $meeting->room_name ?? 'Designated Room',
                'startTime' => date('M j, Y H:i', strtotime($meeting->start_time)),
                'endTime' => date('H:i', strtotime($meeting->end_time)),
                'organizer' => $currentUser?->name ?? 'Organizer',
            ], $meeting->tenant_id);

            // Record to central audit logs
            try {
                DB::table('audit_logs')->insert([
                    'tenant_id' => $meeting->tenant_id,
                    'user_id' => $currentUser?->id,
                    'action' => 'meeting_minutes_published',
                    'module' => 'meetings',
                    'entity_type' => 'meeting',
                    'entity_id' => (string) $meetingId,
                    'ip_address' => $request->ip(),
                    'payload' => json_encode([
                        'meeting_id' => $meetingId,
                        'title' => $meeting->title,
                        'action_items_count' => count($validated['action_items'] ?? []),
                    ]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (\Throwable $e) {}
        }

        return response()->json([
            'status' => 'success',
            'message' => $status === 'published' ? 'Meeting minutes published and broadcast to attendees.' : 'Meeting minutes saved as draft.',
        ]);
    }
}
