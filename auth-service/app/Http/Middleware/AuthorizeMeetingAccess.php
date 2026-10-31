<?php

namespace App\Http\Middleware;

use App\Services\TenantAccess;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuthorizeMeetingAccess
{
    public function handle(Request $request, Closure $next)
    {
        $controller = class_basename($request->route()->getControllerClass());
        $method = $request->route()->getActionMethod();
        $user = $request->user();
        $admin = TenantAccess::isAdmin($user);
        $id = $request->route('id');
        $meeting = null;

        if (in_array($controller, ['MeetingController', 'MeetingMinutesController', 'MeetingActionItemController'], true) && $id) {
            if ($controller === 'MeetingActionItemController' && in_array($method, ['updateStatus', 'destroy'], true)) {
                $action = DB::table('meeting_action_items')->where('id', $id)->first();
                abort_unless($action, 404);
                $meeting = TenantAccess::record('meetings', $action->meeting_id, $request);
                if ($method === 'updateStatus') {
                    abort_unless($admin || $action->assignee_id == $user->id || $meeting->organizer_id == $user->id, 403);
                }
            } else {
                $meeting = TenantAccess::record('meetings', $id, $request);
            }
            if (!$request->isMethodSafe() && !in_array($method, ['rsvp', 'updateStatus'], true)) {
                abort_unless($admin || $meeting->organizer_id == $user->id, 403, 'Only the organizer or a workspace administrator may change this meeting.');
            }
            if (in_array($method, ['approve', 'reject'], true)) {
                abort_unless($admin, 403);
            }
            if ($attachment = $request->route('attachmentId')) {
                abort_unless(DB::table('meeting_attachments')->where('id', $attachment)->where('meeting_id', $meeting->id)->exists(), 404);
            }
        }

        if ($controller === 'MeetingRoomController' && $id) {
            TenantAccess::record('meeting_rooms', $id, $request);
        }
        if ($roomId = $request->route('roomId')) {
            TenantAccess::record('meeting_rooms', $roomId, $request);
        }
        if ($controller === 'MeetingLogisticsController' && $id) {
            TenantAccess::record(str_contains(strtolower($method), 'staff') ? 'meeting_staff' : 'meeting_materials', $id, $request);
        }
        if ($controller === 'MeetingIssueController' && $id) {
            TenantAccess::record('meeting_room_issues', $id, $request);
        }
        if (!$request->isMethodSafe() && (in_array($controller, ['MeetingRoomController', 'MeetingLogisticsController', 'MeetingSettingController', 'MeetingTelegramController'], true)
            || ($controller === 'MeetingIssueController' && $method === 'updateStatus'))) {
            abort_unless($admin, 403, 'Workspace administrator access is required.');
        }

        if (!$request->isMethodSafe()) {
            if ($request->filled('room_id')) TenantAccess::record('meeting_rooms', $request->input('room_id'), $request);
            foreach ((array) $request->input('materials', []) as $material) {
                abort_unless(is_array($material) && isset($material['material_id']), 422);
                TenantAccess::record('meeting_materials', $material['material_id'], $request);
            }
            foreach ((array) $request->input('staff_ids', []) as $staffId) TenantAccess::record('meeting_staff', $staffId, $request);
            foreach ((array) $request->input('attendees', []) as $attendee) {
                if (!empty($attendee['user_id'])) abort_unless(DB::table('tenant_users')->where('tenant_id', $meeting?->tenant_id ?? $user->tenant_id)->where('user_id', $attendee['user_id'])->where('status', 'active')->exists(), 422);
            }
            $assignee = $request->input('assignee_id') ?? $request->input('assigned_to');
            if ($assignee) abort_unless(DB::table('tenant_users')->where('tenant_id', $meeting?->tenant_id ?? $user->tenant_id)->where('user_id', $assignee)->where('status', 'active')->exists(), 422, 'Assignee must belong to the workspace.');
        }
        return $next($request);
    }
}
