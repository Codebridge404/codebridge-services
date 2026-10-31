<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Meeting\BatchUpdateMeetingSettingsRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * [BD-0073] Meeting Configuration, Policies & Default Templates Controller
 *
 * @ticket BD-0073
 * @component auth-service
 * @file codebridge-services/auth-service/app/Http/Controllers/Api/V1/MeetingSettingController.php
 * @related-model DB table meeting_settings, App\Models\Tenant
 * @entry-point MeetingSettingController::getSettings(), updateSettings()
 * @routes
 *  - GET /api/v1/meeting-settings
 *  - PUT /api/v1/meeting-settings
 * @date-time 2026-10-23T15:40:00+07:00
 *
 * Process Capabilities:
 * Step 1 (Tenant Policy Retrieval): Loads tenant-wide meeting policies, duration limits, and buffer intervals.
 * Step 2 (Template Management): Configures default agendas and meeting types (1-on-1, Standup, Board Meeting).
 * Step 3 (Working Hours Enforcement): Sets allowed booking hours to prevent after-hours room scheduling.
 * Step 4 (Integration Credentials): Manages external calendar sync and third-party webhook integrations.
 * Step 5 (Quota Configuration): Restricts maximum concurrent active meetings per subscription plan.
 */
class MeetingSettingController extends Controller
{
    /**
     * Get all dynamic system settings for current workspace.
     * GET /api/v1/meetings/system-settings
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $tenantId = $user?->tenant_id;

        $query = DB::table('meeting_system_settings');
        if ($user && $user->role !== 'super_admin' && !empty($tenantId)) {
            $query->where(function ($q) use ($tenantId) {
                $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id');
            });
        }

        $query->where('setting_key', 'not like', '%token%')->where('setting_key', 'not like', '%secret%');
        $settings = $query->orderBy('category', 'asc')->orderBy('setting_key', 'asc')->get();

        // Seed default corporate governance policies if empty
        if ($settings->isEmpty() && !empty($tenantId)) {
            $defaults = [
                // Role Permissions Matrix
                ['setting_key' => 'role.employee.can_book', 'setting_value' => 'true', 'category' => 'role_permissions', 'data_type' => 'boolean', 'display_name' => 'Allow Employees to Book Meetings', 'description' => 'Enables regular employees to submit meeting reservations directly.'],
                ['setting_key' => 'role.employee.require_approval', 'setting_value' => 'false', 'category' => 'role_permissions', 'data_type' => 'boolean', 'display_name' => 'Require Admin Approval for Employee Bookings', 'description' => 'If enabled, all employee reservations remain PENDING until approved.'],
                ['setting_key' => 'role.employee.can_request_materials', 'setting_value' => 'true', 'category' => 'role_permissions', 'data_type' => 'boolean', 'display_name' => 'Allow Employees to Request Equipment & Materials', 'description' => 'Allows employees to allocate projectors, speakerphones, etc.'],

                // Booking Policies
                ['setting_key' => 'booking.approval_threshold_capacity', 'setting_value' => '20', 'category' => 'booking_policy', 'data_type' => 'number', 'display_name' => 'Boardroom Capacity Approval Threshold', 'description' => 'Rooms with capacity equal or greater require mandatory Admin approval.'],
                ['setting_key' => 'booking.operating_hours_start', 'setting_value' => '08:00', 'category' => 'booking_policy', 'data_type' => 'string', 'display_name' => 'Facility Standard Opening Time', 'description' => 'Start of allowed booking hours (HH:mm).'],
                ['setting_key' => 'booking.operating_hours_end', 'setting_value' => '18:00', 'category' => 'booking_policy', 'data_type' => 'string', 'display_name' => 'Facility Standard Closing Time', 'description' => 'End of allowed booking hours (HH:mm).'],
                ['setting_key' => 'booking.allow_weekend_booking', 'setting_value' => 'false', 'category' => 'booking_policy', 'data_type' => 'boolean', 'display_name' => 'Allow Weekend Reservations', 'description' => 'Permits reserving meeting rooms on Saturday and Sunday.'],
                ['setting_key' => 'booking.max_advance_days', 'setting_value' => '60', 'category' => 'booking_policy', 'data_type' => 'number', 'display_name' => 'Maximum Advance Booking Horizon (Days)', 'description' => 'Prevents reservations beyond this number of days into the future.'],

                // Scheduling Defaults & Turnover
                ['setting_key' => 'scheduling.default_duration_min', 'setting_value' => '30', 'category' => 'scheduling_defaults', 'data_type' => 'number', 'display_name' => 'Default Meeting Duration (Minutes)', 'description' => 'Initial duration pre-selected in reservation dialogs.'],
                ['setting_key' => 'scheduling.room_buffer_min', 'setting_value' => '5', 'category' => 'scheduling_defaults', 'data_type' => 'number', 'display_name' => 'Room Turnover Buffer (Minutes)', 'description' => 'Turnover sanitation and AV reset buffer required between meetings.'],
                ['setting_key' => 'scheduling.checkin_grace_period_min', 'setting_value' => '15', 'category' => 'scheduling_defaults', 'data_type' => 'number', 'display_name' => 'Check-in Grace Period (Minutes)', 'description' => 'Grace period before unattended bookings are auto-released.'],

                // Notifications
                ['setting_key' => 'notification.default_lead_minutes', 'setting_value' => '10', 'category' => 'notifications', 'data_type' => 'number', 'display_name' => 'Default Reminder Lead Time (Minutes)', 'description' => 'Time prior to meeting start when reminder alerts are dispatched.'],
            ];

            foreach ($defaults as $d) {
                DB::table('meeting_system_settings')->insert(array_merge($d, [
                    'tenant_id' => $tenantId,
                    'is_public' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
            }

            $settings = DB::table('meeting_system_settings')->where('tenant_id', $tenantId)->where('setting_key', 'not like', '%token%')->where('setting_key', 'not like', '%secret%')->get();
        }

        return response()->json(['status' => 'success', 'data' => $settings]);
    }

    /**
     * Batch update dynamic settings.
     * PUT /api/v1/meetings/system-settings/batch
     */
    public function batchUpdate(BatchUpdateMeetingSettingsRequest $request)
    {
        $validated = $request->validated();

        $user = $request->user();
        $tenantId = $user?->tenant_id;

        foreach ($validated['settings'] as $item) {
            $key = $item['setting_key'];
            $val = $item['setting_value'] ?? '';

            $exists = DB::table('meeting_system_settings')
                ->where('setting_key', $key)
                ->when($tenantId, fn($q) => $q->where('tenant_id', $tenantId))
                ->first();

            if ($exists) {
                DB::table('meeting_system_settings')->where('id', $exists->id)->update([
                    'setting_value' => $val,
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('meeting_system_settings')->insert([
                    'tenant_id' => $tenantId,
                    'setting_key' => $key,
                    'setting_value' => $val,
                    'display_name' => ucwords(str_replace(['.', '_'], ' ', $key)),
                    'is_public' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        $fresh = DB::table('meeting_system_settings')
            ->when($tenantId, fn($q) => $q->where('tenant_id', $tenantId))
            ->get();

        return response()->json([
            'status' => 'success',
            'message' => 'System settings updated successfully.',
            'data' => $fresh,
        ]);
    }

    /**
     * Reset settings to defaults.
     * POST /api/v1/meetings/system-settings/reset
     */
    public function reset(Request $request)
    {
        $user = $request->user();
        $tenantId = $user?->tenant_id;

        if ($tenantId) {
            DB::table('meeting_system_settings')->where('tenant_id', $tenantId)->delete();
        }

        // Call index to re-seed defaults
        return $this->index($request);
    }
}
