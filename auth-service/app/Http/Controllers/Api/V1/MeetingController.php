<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MeetingController extends Controller
{
    /**
     * List meetings for the workspace with optional date/room/status filters.
     * Opportunistically runs ghost-room auto-release and advance countdown checks.
     */
    public function index(Request $request)
    {
        $currentUser = $request->user();
        $tenantId = $currentUser?->tenant_id;
        $date = $request->query('date');
        $roomId = $request->query('room_id');
        $status = $request->query('status');
        $approvalOnly = $request->boolean('pending_approval_only');

        // Opportunistic automated background turnovers
        self::releaseUnattendedMeetings($tenantId);
        self::dispatchAdvanceReminders($tenantId);

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
        if ($approvalOnly) {
            $query->where('m.status', 'pending_approval');
        }

        $meetings = $query->select(
            'm.*',
            'r.name as room_name', 'r.floor as room_floor', 'r.capacity as room_capacity',
            'u.name as organizer_name', 'u.email as organizer_email'
        )->orderBy('m.start_time', 'asc')->get();

        // Attach attendees, materials, staff counts and minutes flag
        foreach ($meetings as $meeting) {
            $meeting->attendees_count = DB::table('meeting_attendees')->where('meeting_id', $meeting->id)->count();
            $meeting->materials_count = DB::table('meeting_material_allocations')->where('meeting_id', $meeting->id)->count();
            $meeting->staff_count = DB::table('meeting_staff_allocations')->where('meeting_id', $meeting->id)->count();
            $meeting->has_minutes = DB::table('meeting_minutes')->where('meeting_id', $meeting->id)->exists();
        }

        return response()->json(['status' => 'success', 'data' => $meetings]);
    }

    /**
     * Create a new meeting with Zero-Conflict checking, equipment allocation, and boardroom threshold governance.
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
            'materials' => 'nullable|array', // [{ material_id: 1, quantity: 2 }]
            'staff_ids' => 'nullable|array', // [1, 2]
            'is_recurring' => 'nullable|boolean',
            'recurrence_type' => 'nullable|string|in:none,daily,weekly,bi_weekly,monthly',
            'occurrences_count' => 'nullable|integer|min:1|max:52',
        ]);

        $currentUser = $request->user();
        $tenantId = $currentUser?->tenant_id;

        $room = DB::table('meeting_rooms')->where('id', $validated['room_id'])->first();
        if (!$room) {
            return response()->json(['status' => 'error', 'message' => 'Selected meeting room does not exist.'], 404);
        }

        // 1. Zero-conflict validation: ensure no overlapping active meeting in this room
        $conflict = $this->findConflict($validated['room_id'], $validated['start_time'], $validated['end_time']);
        if ($conflict) {
            return response()->json([
                'status' => 'error',
                'code' => 'SCHEDULE_CONFLICT',
                'message' => 'The selected room is already booked during this time interval.',
                'conflict' => $conflict,
            ], 422);
        }

        // 2. Boardroom Capacity Governance Gate
        $approvalThreshold = 20;
        $setting = DB::table('meeting_system_settings')
            ->where('setting_key', 'booking.approval_threshold_capacity')
            ->when($tenantId, fn($q) => $q->where('tenant_id', $tenantId))
            ->first();
        if ($setting && is_numeric($setting->setting_value)) {
            $approvalThreshold = (int) $setting->setting_value;
        }

        $requiresApproval = ($room->capacity >= $approvalThreshold);
        $initialStatus = $requiresApproval ? 'pending_approval' : 'scheduled';

        // 3. Materials Stock Validation & Reservation
        if (!empty($validated['materials']) && is_array($validated['materials'])) {
            foreach ($validated['materials'] as $mat) {
                $item = DB::table('meeting_materials')->where('id', $mat['material_id'])->first();
                $qtyReq = $mat['quantity'] ?? 1;
                if ($item && $item->quantity_available < $qtyReq) {
                    return response()->json([
                        'status' => 'error',
                        'code' => 'INSUFFICIENT_STOCK',
                        'message' => "Insufficient stock for '{$item->name}'. Available: {$item->quantity_available}, Requested: {$qtyReq}.",
                    ], 422);
                }
            }
        }

        $seriesId = !empty($validated['is_recurring']) && ($validated['recurrence_type'] ?? 'none') !== 'none'
            ? Str::uuid()->toString()
            : null;

        // 4. Create primary meeting record
        $meetingId = DB::table('meetings')->insertGetId([
            'tenant_id' => $tenantId,
            'room_id' => $validated['room_id'],
            'organizer_id' => $currentUser->id,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'start_time' => $validated['start_time'],
            'end_time' => $validated['end_time'],
            'status' => $initialStatus,
            'requires_approval' => $requiresApproval,
            'meeting_type' => $validated['meeting_type'] ?? 'internal',
            'meeting_link' => $validated['meeting_link'] ?? null,
            'recurring_series_id' => $seriesId,
            'recurrence_type' => $validated['recurrence_type'] ?? 'none',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 5. Deduct material stock & record allocations
        if (!empty($validated['materials']) && is_array($validated['materials'])) {
            foreach ($validated['materials'] as $mat) {
                $qty = $mat['quantity'] ?? 1;
                DB::table('meeting_material_allocations')->insert([
                    'meeting_id' => $meetingId,
                    'material_id' => $mat['material_id'],
                    'quantity_allocated' => $qty,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('meeting_materials')->where('id', $mat['material_id'])->decrement('quantity_available', $qty);
            }
        }

        // 6. Record staff allocations
        if (!empty($validated['staff_ids']) && is_array($validated['staff_ids'])) {
            foreach ($validated['staff_ids'] as $staffId) {
                DB::table('meeting_staff_allocations')->insert([
                    'meeting_id' => $meetingId,
                    'staff_id' => $staffId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // 7. Insert organizer attendee
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

        // 8. Insert attendee invitations & dispatch in-app notifications
        if (!empty($validated['attendees']) && is_array($validated['attendees'])) {
            foreach ($validated['attendees'] as $att) {
                $uid = $att['user_id'] ?? null;
                DB::table('meeting_attendees')->insert([
                    'meeting_id' => $meetingId,
                    'user_id' => $uid,
                    'name' => $att['name'] ?? 'Guest Attendee',
                    'email' => $att['email'] ?? null,
                    'role' => $att['role'] ?? 'attendee',
                    'rsvp_status' => 'pending',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                if ($uid && $uid != $currentUser->id) {
                    DB::table('meeting_notifications')->insert([
                        'tenant_id' => $tenantId,
                        'user_id' => $uid,
                        'meeting_id' => $meetingId,
                        'type' => 'invitation',
                        'title' => "Meeting Invitation: {$validated['title']}",
                        'message' => "{$currentUser->name} invited you to '{$validated['title']}' on " . date('M d, Y H:i', strtotime($validated['start_time'])) . " in {$room->name}.",
                        'status' => 'unread',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }

        // 9. If recurring series requested, generate subsequent occurrences
        if ($seriesId && ($validated['occurrences_count'] ?? 1) > 1) {
            $this->generateRecurringOccurrences($meetingId, $validated, $seriesId, $currentUser, $tenantId, $requiresApproval, $initialStatus);
        }

        // 10. Record central audit log
        $this->recordMeetingAuditLog($currentUser->id, $currentUser->name, 'meeting_created', [
            'meeting_id' => $meetingId,
            'title' => $validated['title'],
            'room' => $room->name,
            'requires_approval' => $requiresApproval,
        ], $request);

        // 11. Dispatch Telegram Confirmation Alert if auto-scheduled
        if (!$requiresApproval) {
            MeetingTelegramController::dispatchTelegramAlert('confirmation', [
                'title' => $validated['title'],
                'room' => $room->name,
                'startTime' => date('M j, Y H:i', strtotime($validated['start_time'])),
                'endTime' => date('H:i', strtotime($validated['end_time'])),
                'organizer' => $currentUser->name,
                'materials' => count($validated['materials'] ?? []) . ' items allocated',
            ], $tenantId);
        }

        $message = $requiresApproval
            ? "Meeting submitted for Boardroom Admin Approval (Room capacity {$room->capacity} >= {$approvalThreshold})."
            : 'Meeting scheduled successfully.';

        return response()->json([
            'status' => 'success',
            'message' => $message,
            'requires_approval' => $requiresApproval,
            'data' => ['id' => $meetingId],
        ], 201);
    }

    /**
     * View complete single meeting details, attendees, allocations, minutes, and attachments.
     */
    public function show($id)
    {
        $meeting = DB::table('meetings as m')
            ->leftJoin('meeting_rooms as r', 'm.room_id', '=', 'r.id')
            ->leftJoin('users as u', 'm.organizer_id', '=', 'u.id')
            ->where('m.id', $id)
            ->select('m.*', 'r.name as room_name', 'r.floor as room_floor', 'r.capacity as room_capacity', 'u.name as organizer_name', 'u.email as organizer_email')
            ->first();

        if (!$meeting) {
            return response()->json(['status' => 'error', 'message' => 'Meeting not found.'], 404);
        }

        $attendees = DB::table('meeting_attendees')->where('meeting_id', $id)->get();
        $materials = DB::table('meeting_material_allocations as ma')
            ->join('meeting_materials as m', 'ma.material_id', '=', 'm.id')
            ->where('ma.meeting_id', $id)
            ->select('ma.*', 'm.name', 'm.category')
            ->get();
        $staff = DB::table('meeting_staff_allocations as sa')
            ->join('meeting_staff as s', 'sa.staff_id', '=', 's.id')
            ->where('sa.meeting_id', $id)
            ->select('sa.*', 's.name', 's.role', 's.phone')
            ->get();
        $minutes = DB::table('meeting_minutes')->where('meeting_id', $id)->first();
        $actions = DB::table('meeting_action_items')->where('meeting_id', $id)->get();
        $attachments = DB::table('meeting_attachments')->where('meeting_id', $id)->get();

        return response()->json([
            'status' => 'success',
            'data' => [
                'meeting' => $meeting,
                'attendees' => $attendees,
                'materials' => $materials,
                'staff' => $staff,
                'minutes' => $minutes,
                'actions' => $actions,
                'attachments' => $attachments,
            ],
        ]);
    }

    /**
     * Update meeting details.
     */
    public function update(Request $request, $id)
    {
        $meeting = DB::table('meetings')->where('id', $id)->first();
        if (!$meeting) {
            return response()->json(['status' => 'error', 'message' => 'Meeting not found.'], 404);
        }

        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'start_time' => 'sometimes|date',
            'end_time' => 'sometimes|date|after:start_time',
            'room_id' => 'sometimes|integer',
        ]);

        DB::table('meetings')->where('id', $id)->update(array_merge($validated, ['updated_at' => now()]));

        $user = $request->user();
        $this->recordMeetingAuditLog($user?->id, $user?->name, 'meeting_updated', ['meeting_id' => $id, 'title' => $validated['title'] ?? $meeting->title], $request);

        return response()->json(['status' => 'success', 'message' => 'Meeting updated.']);
    }

    /**
     * Delete meeting and release resources.
     */
    public function destroy(Request $request, $id)
    {
        $meeting = DB::table('meetings')->where('id', $id)->first();
        if (!$meeting) {
            return response()->json(['status' => 'error', 'message' => 'Meeting not found.'], 404);
        }

        $this->restoreAllocatedMaterials($id);
        DB::table('meetings')->where('id', $id)->delete();

        $user = $request->user();
        $this->recordMeetingAuditLog($user?->id, $user?->name, 'meeting_deleted', ['meeting_id' => $id, 'title' => $meeting->title], $request);

        return response()->json(['status' => 'success', 'message' => 'Meeting deleted and equipment liberated.']);
    }

    /**
     * Approve Boardroom / Large Meeting.
     * PATCH /api/v1/meetings/{id}/approve
     */
    public function approve(Request $request, $id)
    {
        $user = $request->user();
        $meeting = DB::table('meetings as m')
            ->leftJoin('meeting_rooms as r', 'm.room_id', '=', 'r.id')
            ->where('m.id', $id)
            ->select('m.*', 'r.name as room_name')
            ->first();

        if (!$meeting) {
            return response()->json(['status' => 'error', 'message' => 'Meeting not found.'], 404);
        }

        DB::table('meetings')->where('id', $id)->update([
            'status' => 'scheduled',
            'approved_by' => $user->id,
            'approved_at' => now(),
            'updated_at' => now(),
        ]);

        // Notify organizer
        DB::table('meeting_notifications')->insert([
            'tenant_id' => $meeting->tenant_id,
            'user_id' => $meeting->organizer_id,
            'meeting_id' => $id,
            'type' => 'approval_required',
            'title' => 'Boardroom Booking Approved',
            'message' => "Your meeting '{$meeting->title}' has been officially approved by an Administrator.",
            'status' => 'unread',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Audit log
        $this->recordMeetingAuditLog($user->id, $user->name, 'meeting_approved', [
            'meeting_id' => $id,
            'title' => $meeting->title,
            'room' => $meeting->room_name,
        ], $request);

        // Telegram alert
        MeetingTelegramController::dispatchTelegramAlert('change', [
            'title' => $meeting->title,
            'room' => $meeting->room_name ?? 'Boardroom',
            'startTime' => date('M j, Y H:i', strtotime($meeting->start_time)),
            'endTime' => date('H:i', strtotime($meeting->end_time)),
            'organizer' => $user->name,
        ], $meeting->tenant_id);

        return response()->json(['status' => 'success', 'message' => 'Meeting booking approved successfully.']);
    }

    /**
     * Reject Boardroom Booking and restore materials.
     * PATCH /api/v1/meetings/{id}/reject
     */
    public function reject(Request $request, $id)
    {
        $meeting = DB::table('meetings')->where('id', $id)->first();
        if (!$meeting) {
            return response()->json(['status' => 'error', 'message' => 'Meeting not found.'], 404);
        }

        DB::table('meetings')->where('id', $id)->update([
            'status' => 'rejected',
            'updated_at' => now(),
        ]);

        // Auto-restock guarantee
        $this->restoreAllocatedMaterials($id);

        $user = $request->user();
        $this->recordMeetingAuditLog($user?->id, $user?->name, 'meeting_rejected', [
            'meeting_id' => $id,
            'title' => $meeting->title,
        ], $request);

        return response()->json(['status' => 'success', 'message' => 'Meeting booking rejected. Materials restored.']);
    }

    /**
     * Early End Release: frees the room immediately and returns allocated equipment back to inventory.
     * PATCH /api/v1/meetings/{id}/end-early
     */
    public function endEarly(Request $request, $id)
    {
        $meeting = DB::table('meetings')->where('id', $id)->first();
        if (!$meeting) {
            return response()->json(['status' => 'error', 'message' => 'Meeting not found.'], 404);
        }

        DB::table('meetings')->where('id', $id)->update([
            'status' => 'completed',
            'ended_early_at' => now(),
            'end_time' => now(),
            'updated_at' => now(),
        ]);

        // Liberate materials immediately
        $this->restoreAllocatedMaterials($id);

        $user = $request->user();
        $this->recordMeetingAuditLog($user?->id, $user?->name, 'meeting_ended_early', [
            'meeting_id' => $id,
            'title' => $meeting->title,
        ], $request);

        return response()->json([
            'status' => 'success',
            'message' => 'Meeting ended early. Room and equipment have been liberated for other bookings.',
        ]);
    }

    /**
     * Reason-based Cancellation with Auto-Restock Guarantee.
     * PATCH /api/v1/meetings/{id}/cancel
     */
    public function cancel(Request $request, $id)
    {
        $validated = $request->validate([
            'cancellation_reason' => 'nullable|string|max:500',
        ]);

        $meeting = DB::table('meetings as m')
            ->leftJoin('meeting_rooms as r', 'm.room_id', '=', 'r.id')
            ->where('m.id', $id)
            ->select('m.*', 'r.name as room_name')
            ->first();

        if (!$meeting) {
            return response()->json(['status' => 'error', 'message' => 'Meeting not found.'], 404);
        }

        $reason = $validated['cancellation_reason'] ?? 'Cancelled by organizer';

        DB::table('meetings')->where('id', $id)->update([
            'status' => 'cancelled',
            'cancellation_reason' => $reason,
            'updated_at' => now(),
        ]);

        // Restore material stock back to inventory
        $this->restoreAllocatedMaterials($id);

        // Alert all attendees
        $attendees = DB::table('meeting_attendees')->where('meeting_id', $id)->whereNotNull('user_id')->get();
        foreach ($attendees as $att) {
            DB::table('meeting_notifications')->insert([
                'tenant_id' => $meeting->tenant_id,
                'user_id' => $att->user_id,
                'meeting_id' => $id,
                'type' => 'cancelled',
                'title' => "Meeting Cancelled: {$meeting->title}",
                'message' => "Reason: " . $reason,
                'status' => 'unread',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $user = $request->user();
        $this->recordMeetingAuditLog($user?->id, $user?->name, 'meeting_cancelled', [
            'meeting_id' => $id,
            'title' => $meeting->title,
            'reason' => $reason,
        ], $request);

        // Telegram alert
        MeetingTelegramController::dispatchTelegramAlert('cancellation', [
            'title' => $meeting->title,
            'room' => $meeting->room_name ?? 'Conference Room',
            'startTime' => date('M j, Y H:i', strtotime($meeting->start_time)),
            'reason' => $reason,
        ], $meeting->tenant_id);

        return response()->json(['status' => 'success', 'message' => 'Meeting cancelled. Equipment stock restored.']);
    }

    /**
     * Update participant RSVP response.
     * PATCH /api/v1/meetings/{id}/rsvp
     */
    public function rsvp(Request $request, $id)
    {
        $validated = $request->validate([
            'rsvp_status' => 'required|string|in:accepted,declined,tentative',
        ]);

        $user = $request->user();
        DB::table('meeting_attendees')
            ->where('meeting_id', $id)
            ->where('user_id', $user->id)
            ->update([
                'rsvp_status' => $validated['rsvp_status'],
                'updated_at' => now(),
            ]);

        return response()->json(['status' => 'success', 'message' => "RSVP updated to '{$validated['rsvp_status']}'."]);
    }

    /**
     * Pre-flight Recurring Conflict Preview: evaluates future occurrences against existing bookings.
     * POST /api/v1/meetings/recurring/preview
     */
    public function previewRecurring(Request $request)
    {
        $validated = $request->validate([
            'room_id' => 'required|integer',
            'start_time' => 'required|date',
            'end_time' => 'required|date|after:start_time',
            'recurrence_type' => 'required|string|in:daily,weekly,bi_weekly,monthly',
            'occurrences_count' => 'required|integer|min:1|max:52',
        ]);

        $occurrences = [];
        $conflictsCount = 0;
        $start = strtotime($validated['start_time']);
        $end = strtotime($validated['end_time']);
        $duration = $end - $start;

        for ($i = 0; $i < $validated['occurrences_count']; $i++) {
            $curStart = $start;
            if ($validated['recurrence_type'] === 'daily') {
                $curStart = strtotime("+{$i} days", $start);
            } elseif ($validated['recurrence_type'] === 'weekly') {
                $curStart = strtotime("+{$i} weeks", $start);
            } elseif ($validated['recurrence_type'] === 'bi_weekly') {
                $step = $i * 2;
                $curStart = strtotime("+{$step} weeks", $start);
            } elseif ($validated['recurrence_type'] === 'monthly') {
                $curStart = strtotime("+{$i} months", $start);
            }

            $curEnd = $curStart + $duration;
            $startStr = date('Y-m-d H:i:s', $curStart);
            $endStr = date('Y-m-d H:i:s', $curEnd);

            $conflict = $this->findConflict($validated['room_id'], $startStr, $endStr);
            if ($conflict) {
                $conflictsCount++;
            }

            $occurrences[] = [
                'index' => $i + 1,
                'start_time' => $startStr,
                'end_time' => $endStr,
                'has_conflict' => !empty($conflict),
                'conflict' => $conflict,
            ];
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'total_occurrences' => count($occurrences),
                'conflicts_count' => $conflictsCount,
                'all_clear' => $conflictsCount === 0,
                'occurrences' => $occurrences,
            ],
        ]);
    }

    /**
     * Meeting Attachments CRUD.
     */
    public function attachments($id)
    {
        $attachments = DB::table('meeting_attachments')->where('meeting_id', $id)->get();
        return response()->json(['status' => 'success', 'data' => $attachments]);
    }

    public function storeAttachment(Request $request, $id)
    {
        $validated = $request->validate([
            'file_name' => 'required|string|max:255',
            'file_url' => 'nullable|string',
            'file_type' => 'nullable|string|max:100',
            'file_size' => 'nullable|integer',
        ]);

        $user = $request->user();
        $meeting = DB::table('meetings')->where('id', $id)->first();
        if (!$meeting) {
            return response()->json(['status' => 'error', 'message' => 'Meeting not found.'], 404);
        }

        $attId = DB::table('meeting_attachments')->insertGetId([
            'meeting_id' => $id,
            'tenant_id' => $meeting->tenant_id,
            'file_name' => $validated['file_name'],
            'file_url' => $validated['file_url'] ?? null,
            'file_type' => $validated['file_type'] ?? 'document',
            'file_size' => $validated['file_size'] ?? 0,
            'uploaded_by' => $user?->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $item = DB::table('meeting_attachments')->where('id', $attId)->first();
        return response()->json(['status' => 'success', 'message' => 'Attachment uploaded.', 'data' => $item], 201);
    }

    public function destroyAttachment($meetingId, $attachmentId)
    {
        DB::table('meeting_attachments')->where('id', $attachmentId)->delete();
        return response()->json(['status' => 'success', 'message' => 'Attachment deleted.']);
    }

    /**
     * Check-in to room (via kiosk door display or mobile).
     */
    public function checkIn($id)
    {
        $meeting = DB::table('meetings')->where('id', $id)->first();
        if (!$meeting) {
            return response()->json(['status' => 'error', 'message' => 'Meeting not found.'], 404);
        }

        DB::table('meetings')->where('id', $id)->update([
            'status' => 'in_progress',
            'checked_in_at' => now(),
            'updated_at' => now(),
        ]);

        $this->recordMeetingAuditLog(null, 'Room Display Kiosk', 'meeting_checked_in', [
            'meeting_id' => $id,
            'title' => $meeting->title,
        ]);

        return response()->json(['status' => 'success', 'message' => 'Checked in successfully. Room marked in progress.']);
    }

    /**
     * Kiosk Door Display Feed for a specific room.
     * GET /api/v1/meetings/room-display/{roomId}
     */
    public function roomDisplay($roomId)
    {
        $room = DB::table('meeting_rooms')->where('id', $roomId)->first();
        if (!$room) {
            return response()->json(['status' => 'error', 'message' => 'Room not found.'], 404);
        }

        // Run opportunistic auto-release
        self::releaseUnattendedMeetings($room->tenant_id);

        $now = now();

        $currentMeeting = DB::table('meetings as m')
            ->leftJoin('users as u', 'm.organizer_id', '=', 'u.id')
            ->where('m.room_id', $roomId)
            ->whereNotIn('m.status', ['cancelled', 'rejected'])
            ->where('m.start_time', '<=', $now)
            ->where('m.end_time', '>=', $now)
            ->select('m.*', 'u.name as organizer_name')
            ->first();

        $nextMeeting = DB::table('meetings as m')
            ->leftJoin('users as u', 'm.organizer_id', '=', 'u.id')
            ->where('m.room_id', $roomId)
            ->whereNotIn('m.status', ['cancelled', 'rejected'])
            ->where('m.start_time', '>', $now)
            ->orderBy('m.start_time', 'asc')
            ->select('m.*', 'u.name as organizer_name')
            ->first();

        return response()->json([
            'status' => 'success',
            'data' => [
                'room' => $room,
                'current_meeting' => $currentMeeting,
                'next_meeting' => $nextMeeting,
                'is_occupied' => !empty($currentMeeting),
            ],
        ]);
    }

    /**
     * Dashboard statistics for MMS.
     */
    public function stats(Request $request)
    {
        $currentUser = $request->user();
        $tenantId = $currentUser?->tenant_id;

        // Run background sweeps
        self::releaseUnattendedMeetings($tenantId);
        self::dispatchAdvanceReminders($tenantId);

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
        $pendingApproval = (clone $mQuery)->where('status', 'pending_approval')->count();
        $totalRooms = $rQuery->count();

        return response()->json([
            'status' => 'success',
            'data' => [
                'today_count' => $totalToday,
                'upcoming_count' => $upcoming,
                'completed_count' => $completed,
                'pending_approval_count' => $pendingApproval,
                'total_rooms' => $totalRooms,
            ],
        ]);
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

    /**
     * Automated Ghost Room Auto-Release:
     * Cancels confirmed meetings past the grace period without kiosk check-in,
     * immediately restocks allocated equipment, frees staff, and alerts organizer.
     */
    public static function releaseUnattendedMeetings(?string $tenantId = null): int
    {
        $graceMinutes = (int) (DB::table('meeting_system_settings')
            ->where('key', 'booking.no_show_auto_release_minutes')
            ->where(function ($q) use ($tenantId) {
                if ($tenantId) $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id');
            })->value('value') ?? 15);

        if ($graceMinutes <= 0) {
            return 0;
        }

        $now = now();
        $deadline = $now->copy()->subMinutes($graceMinutes);

        $query = DB::table('meetings as m')
            ->leftJoin('meeting_rooms as r', 'm.room_id', '=', 'r.id')
            ->whereIn('m.status', ['confirmed', 'scheduled'])
            ->where('m.start_time', '<=', $deadline)
            ->whereNull('m.checked_in_at');

        if ($tenantId) {
            $query->where('m.tenant_id', $tenantId);
        }

        $expired = $query->select('m.*', 'r.name as room_name')->get();
        $count = 0;

        foreach ($expired as $m) {
            $reason = "AUTO_RELEASE_NO_SHOW: Room released automatically due to absence of check-in within {$graceMinutes} minutes.";

            DB::table('meetings')->where('id', $m->id)->update([
                'status' => 'cancelled',
                'cancellation_reason' => $reason,
                'updated_at' => $now,
            ]);

            // Restock materials
            $allocations = DB::table('meeting_material_allocations')->where('meeting_id', $m->id)->get();
            foreach ($allocations as $alloc) {
                DB::table('meeting_materials')
                    ->where('id', $alloc->material_id)
                    ->increment('quantity_available', $alloc->quantity_allocated);
            }

            // In-app alert to organizer
            DB::table('meeting_notifications')->insert([
                'tenant_id' => $m->tenant_id,
                'user_id' => $m->organizer_id,
                'meeting_id' => $m->id,
                'type' => 'cancelled',
                'title' => "Room Auto-Released: {$m->title}",
                'message' => $reason,
                'status' => 'unread',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // Dispatch Telegram cancellation alert
            MeetingTelegramController::dispatchTelegramAlert('cancellation', [
                'title' => $m->title,
                'room' => $m->room_name ?? 'Designated Room',
                'startTime' => date('Y-m-d H:i', strtotime($m->start_time)),
                'reason' => $reason,
            ], $m->tenant_id);

            // Record audit log
            try {
                DB::table('audit_logs')->insert([
                    'user_id' => $m->organizer_id,
                    'user_name' => 'System Auto-Release',
                    'action' => 'meeting_auto_released',
                    'module' => 'meetings',
                    'ip_address' => '127.0.0.1',
                    'payload' => json_encode([
                        'meeting_id' => $m->id,
                        'title' => $m->title,
                        'room' => $m->room_name,
                        'grace_minutes' => $graceMinutes,
                    ]),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } catch (\Throwable $e) {}

            $count++;
        }

        return $count;
    }

    /**
     * Dispatch dynamic advance countdown reminders (10-15m before meeting starts).
     */
    public static function dispatchAdvanceReminders(?string $tenantId = null): int
    {
        $leadMinutes = (int) (DB::table('meeting_system_settings')
            ->where('key', 'notification.default_lead_minutes')
            ->where(function ($q) use ($tenantId) {
                if ($tenantId) $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id');
            })->value('value') ?? 15);

        if ($leadMinutes <= 0) {
            $leadMinutes = 15;
        }

        $now = now();
        $windowStart = $now;
        $windowEnd = $now->copy()->addMinutes($leadMinutes + 2);

        $query = DB::table('meetings as m')
            ->leftJoin('meeting_rooms as r', 'm.room_id', '=', 'r.id')
            ->leftJoin('users as u', 'm.organizer_id', '=', 'u.id')
            ->whereIn('m.status', ['confirmed', 'scheduled'])
            ->whereBetween('m.start_time', [$windowStart, $windowEnd]);

        if ($tenantId) {
            $query->where('m.tenant_id', $tenantId);
        }

        $upcoming = $query->select('m.*', 'r.name as room_name', 'u.name as organizer_name')->get();
        $dispatched = 0;

        foreach ($upcoming as $m) {
            $alreadyReminded = DB::table('meeting_notifications')
                ->where('meeting_id', $m->id)
                ->where('type', 'reminder')
                ->exists();

            if ($alreadyReminded) {
                continue;
            }

            $minsUntil = max(1, (int) round(($now->diffInSeconds(new \DateTime($m->start_time))) / 60));

            // Notify organizer
            DB::table('meeting_notifications')->insert([
                'tenant_id' => $m->tenant_id,
                'user_id' => $m->organizer_id,
                'meeting_id' => $m->id,
                'type' => 'reminder',
                'title' => "Reminder: {$m->title} starts in {$minsUntil}m",
                'message' => "Starting at " . date('H:i', strtotime($m->start_time)) . " in {$m->room_name}. Please arrive or check in.",
                'status' => 'unread',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // Notify attendees
            $attendees = DB::table('meeting_attendees')->where('meeting_id', $m->id)->whereNotNull('user_id')->get();
            foreach ($attendees as $att) {
                DB::table('meeting_notifications')->insert([
                    'tenant_id' => $m->tenant_id,
                    'user_id' => $att->user_id,
                    'meeting_id' => $m->id,
                    'type' => 'reminder',
                    'title' => "Reminder: {$m->title} starts in {$minsUntil}m",
                    'message' => "Starting at " . date('H:i', strtotime($m->start_time)) . " in {$m->room_name}.",
                    'status' => 'unread',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            // Dispatch Telegram countdown reminder alert
            MeetingTelegramController::dispatchTelegramAlert('reminder', [
                'title' => $m->title,
                'room' => $m->room_name ?? 'Designated Room',
                'startTime' => date('H:i', strtotime($m->start_time)),
                'leadMinutes' => (string) $minsUntil,
                'organizer' => $m->organizer_name ?? 'Organizer',
            ], $m->tenant_id);

            $dispatched++;
        }

        return $dispatched;
    }

    /**
     * Record entry in central audit logs table.
     */
    protected function recordMeetingAuditLog(?int $userId, ?string $userName, string $action, array $payload, ?Request $request = null): void
    {
        try {
            DB::table('audit_logs')->insert([
                'user_id' => $userId,
                'user_name' => $userName ?? 'System',
                'action' => $action,
                'module' => 'meetings',
                'ip_address' => $request?->ip() ?? '127.0.0.1',
                'payload' => json_encode($payload),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {}
    }

    // Helper: Find overlapping active meeting
    private function findConflict($roomId, $startTime, $endTime, $excludeMeetingId = null)
    {
        return DB::table('meetings')
            ->where('room_id', $roomId)
            ->whereNotIn('status', ['cancelled', 'rejected'])
            ->when($excludeMeetingId, fn($q) => $q->where('id', '!=', $excludeMeetingId))
            ->where(function ($q) use ($startTime, $endTime) {
                $q->whereBetween('start_time', [$startTime, $endTime])
                  ->orWhereBetween('end_time', [$startTime, $endTime])
                  ->orWhere(function ($sub) use ($startTime, $endTime) {
                      $sub->where('start_time', '<=', $startTime)
                          ->where('end_time', '>=', $endTime);
                  });
            })->first();
    }

    // Helper: Restore material stock back to inventory
    private function restoreAllocatedMaterials($meetingId)
    {
        $allocations = DB::table('meeting_material_allocations')->where('meeting_id', $meetingId)->get();
        foreach ($allocations as $alloc) {
            DB::table('meeting_materials')
                ->where('id', $alloc->material_id)
                ->increment('quantity_available', $alloc->quantity_allocated);
        }
    }

    // Helper: Generate subsequent occurrences for recurring meetings
    private function generateRecurringOccurrences($firstMeetingId, $validated, $seriesId, $currentUser, $tenantId, $requiresApproval, $initialStatus)
    {
        $start = strtotime($validated['start_time']);
        $end = strtotime($validated['end_time']);
        $duration = $end - $start;

        for ($i = 1; $i < $validated['occurrences_count']; $i++) {
            $curStart = $start;
            if ($validated['recurrence_type'] === 'daily') {
                $curStart = strtotime("+{$i} days", $start);
            } elseif ($validated['recurrence_type'] === 'weekly') {
                $curStart = strtotime("+{$i} weeks", $start);
            } elseif ($validated['recurrence_type'] === 'bi_weekly') {
                $step = $i * 2;
                $curStart = strtotime("+{$step} weeks", $start);
            } elseif ($validated['recurrence_type'] === 'monthly') {
                $curStart = strtotime("+{$i} months", $start);
            }

            $curEnd = $curStart + $duration;
            $startStr = date('Y-m-d H:i:s', $curStart);
            $endStr = date('Y-m-d H:i:s', $curEnd);

            if ($this->findConflict($validated['room_id'], $startStr, $endStr)) {
                continue; // Skip conflicted slot
            }

            $nextId = DB::table('meetings')->insertGetId([
                'tenant_id' => $tenantId,
                'room_id' => $validated['room_id'],
                'organizer_id' => $currentUser->id,
                'title' => $validated['title'],
                'description' => $validated['description'] ?? null,
                'start_time' => $startStr,
                'end_time' => $endStr,
                'status' => $initialStatus,
                'requires_approval' => $requiresApproval,
                'meeting_type' => $validated['meeting_type'] ?? 'internal',
                'meeting_link' => $validated['meeting_link'] ?? null,
                'recurring_series_id' => $seriesId,
                'recurrence_type' => $validated['recurrence_type'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Copy attendees
            if (!empty($validated['attendees'])) {
                foreach ($validated['attendees'] as $att) {
                    DB::table('meeting_attendees')->insert([
                        'meeting_id' => $nextId,
                        'user_id' => $att['user_id'] ?? null,
                        'name' => $att['name'] ?? 'Attendee',
                        'email' => $att['email'] ?? null,
                        'role' => 'attendee',
                        'rsvp_status' => 'pending',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }
}
