<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * [BD-0130] Production & Demo Meeting Management System (MMS) Seeder.
 * Seeds comprehensive data covering all 9 Open Group tags:
 * 1. MeetingRoom (Boardroom fleet, capacities, floor, amenities, kiosk displays)
 * 2. Meeting (Calendar schedules, upcoming, in-progress, completed, attendees)
 * 3. MeetingActionItem (Post-meeting assigned tasks, due dates, statuses)
 * 4. MeetingIssue (Facility defect tickets, severity, repair status)
 * 5. MeetingLogistics (AV hardware materials, stock levels, allocations, support staff)
 * 6. MeetingMinutes (MoM notes, formal decisions, published states)
 * 7. MeetingNotification (In-app alerts, reminder notices, read states)
 * 8. MeetingSetting (Turnover buffers, auto-release timers, advance booking limits)
 * 9. MeetingTelegram (Bot config, chat ID, Markdown alert notification templates)
 */
class MeetingSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        // 1. Resolve Demo Organization Tenant (Sunny Cafe Phnom Penh)
        $tenant = DB::table('tenants')->where('slug', 'sunny-cafe-pp')->first()
            ?? DB::table('tenants')->where('slug', 'codebridge-platform')->first()
            ?? DB::table('tenants')->first();

        if (!$tenant) {
            $this->command?->warn('No tenant found. Please run TenantSeeder first.');
            return;
        }

        $tenantId = $tenant->id;

        // Resolve Key Demo Users
        $orgAdmin = DB::table('users')->where('email', 'admin@sunnycafe.kh')->first()
            ?? DB::table('users')->where('email', 'codebridge2026@gmail.com')->first();
        $cashier = DB::table('users')->where('email', 'cashier@sunnycafe.kh')->first();
        $superAdmin = DB::table('users')->where('email', 'codebridge2026@gmail.com')->first();

        $organizerId = $orgAdmin?->id ?? 1;
        $attendeeId = $cashier?->id ?? $organizerId;

        // Resolve Outlet
        $outlet = DB::table('outlets')->where('tenant_id', $tenantId)->first();
        $outletId = $outlet?->id;
        $outletUuid = $outlet?->uuid;

        // =========================================================================
        // 2. TAG: MeetingSetting - System Governance Rules
        // =========================================================================
        $defaultSettings = [
            [
                'setting_key'   => 'auto_release_unattended_minutes',
                'setting_value' => '15',
                'category'      => 'booking_policy',
                'data_type'     => 'integer',
                'display_name'  => 'Auto-Release Unattended Timeout',
                'description'   => 'Automatically release conference room if attendees do not check in within 15 minutes of scheduled start.',
                'is_public'     => true,
            ],
            [
                'setting_key'   => 'default_turnover_buffer_minutes',
                'setting_value' => '5',
                'category'      => 'booking_policy',
                'data_type'     => 'integer',
                'display_name'  => 'Turnover Sanitation Buffer',
                'description'   => 'Buffer window enforced between consecutive room reservations for cleaning and AV reset.',
                'is_public'     => true,
            ],
            [
                'setting_key'   => 'max_advance_booking_days',
                'setting_value' => '90',
                'category'      => 'booking_policy',
                'data_type'     => 'integer',
                'display_name'  => 'Maximum Advance Booking Window',
                'description'   => 'Maximum number of days in advance an employee can reserve a meeting room.',
                'is_public'     => true,
            ],
            [
                'setting_key'   => 'allow_ad_hoc_kiosk_booking',
                'setting_value' => 'true',
                'category'      => 'kiosk_display',
                'data_type'     => 'boolean',
                'display_name'  => 'Permit Ad-Hoc Tablet Signage Booking',
                'description'   => 'Allow 1-tap 15-minute or 30-minute instant reservations on wall-mounted room tablets.',
                'is_public'     => true,
            ],
            [
                'setting_key'   => 'require_approval_capacity_threshold',
                'setting_value' => '20',
                'category'      => 'approval_policy',
                'data_type'     => 'integer',
                'display_name'  => 'Manager Approval Capacity Threshold',
                'description'   => 'Large boardrooms with capacity exceeding 20 require executive or office manager sign-off.',
                'is_public'     => true,
            ],
        ];

        foreach ($defaultSettings as $setting) {
            DB::table('meeting_system_settings')->updateOrInsert(
                ['tenant_id' => $tenantId, 'setting_key' => $setting['setting_key']],
                array_merge($setting, ['created_at' => $now, 'updated_at' => $now])
            );
        }

        // =========================================================================
        // 3. TAG: MeetingRoom - Boardroom Fleet & Capacity
        // =========================================================================
        $roomsData = [
            [
                'name'        => 'Grand Mekong Boardroom',
                'code'        => 'BD-401',
                'capacity'    => 24,
                'location'    => 'Main Tower, Executive Wing',
                'floor'       => '4th Floor',
                'hourly_rate' => 50.00,
                'currency'    => 'USD',
                'status'      => 'active',
                'is_active'   => true,
                'amenities'   => json_encode([
                    'video_conferencing',
                    'dual_displays',
                    'digital_whiteboard',
                    'crestron_audio',
                    'conference_phone',
                    'hdmi_plug',
                ]),
            ],
            [
                'name'        => 'Tonle Sap Strategy Room',
                'code'        => 'BD-301',
                'capacity'    => 12,
                'location'    => 'West Wing, Business Operations',
                'floor'       => '3rd Floor',
                'hourly_rate' => 30.00,
                'currency'    => 'USD',
                'status'      => 'active',
                'is_active'   => true,
                'amenities'   => json_encode([
                    'video_conferencing',
                    'whiteboard',
                    'tv_display',
                    'hdmi_plug',
                ]),
            ],
            [
                'name'        => 'Bokor Executive Huddle',
                'code'        => 'HD-201',
                'capacity'    => 6,
                'location'    => 'East Wing, Agile Corner',
                'floor'       => '2nd Floor',
                'hourly_rate' => 15.00,
                'currency'    => 'USD',
                'status'      => 'active',
                'is_active'   => true,
                'amenities'   => json_encode([
                    'tv_display',
                    'hdmi_plug',
                    'whiteboard',
                ]),
            ],
            [
                'name'        => 'Angkor Innovation Lab',
                'code'        => 'LB-101',
                'capacity'    => 16,
                'location'    => 'Ground Floor, Tech Studio',
                'floor'       => '1st Floor',
                'hourly_rate' => 40.00,
                'currency'    => 'USD',
                'status'      => 'active',
                'is_active'   => true,
                'amenities'   => json_encode([
                    'smart_board',
                    '360_camera',
                    'wireless_presentation',
                    'high_speed_wifi',
                ]),
            ],
        ];

        $roomMap = [];
        foreach ($roomsData as $r) {
            $existing = DB::table('meeting_rooms')
                ->where('tenant_id', $tenantId)
                ->where('code', $r['code'])
                ->first();

            if ($existing) {
                $roomId = $existing->id;
                DB::table('meeting_rooms')->where('id', $roomId)->update(array_merge($r, ['updated_at' => $now]));
            } else {
                $roomId = DB::table('meeting_rooms')->insertGetId(array_merge($r, [
                    'uuid'        => (string) Str::uuid(),
                    'tenant_id'   => $tenantId,
                    'outlet_id'   => $outletId,
                    'outlet_uuid' => $outletUuid,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ]));
            }
            $roomMap[$r['code']] = $roomId;
        }

        // 3.1 Tablet Door Display Kiosks (meeting_room_displays)
        $kiosks = [
            [
                'room_code'    => 'BD-401',
                'display_name' => 'Wallmount iPad Pro - Grand Mekong',
                'pin'          => '4011',
            ],
            [
                'room_code'    => 'BD-301',
                'display_name' => 'Wallmount Galaxy Tab - Tonle Sap',
                'pin'          => '3011',
            ],
        ];

        foreach ($kiosks as $k) {
            $roomId = $roomMap[$k['room_code']] ?? null;
            if (!$roomId) continue;

            $tokenHash = hash('sha256', "mms_kiosk_{$tenantId}_{$roomId}_codebridge_2026");
            DB::table('meeting_room_displays')->updateOrInsert(
                ['tenant_id' => $tenantId, 'room_id' => $roomId],
                [
                    'uuid'              => (string) Str::uuid(),
                    'device_token_hash' => $tokenHash,
                    'screen_pin_hash'   => Hash::make($k['pin']),
                    'display_name'      => $k['display_name'],
                    'status'            => 'active',
                    'last_ping_at'      => $now,
                    'failed_attempts'   => 0,
                    'locked_until'      => null,
                    'created_at'        => $now,
                    'updated_at'        => $now,
                ]
            );
        }

        // =========================================================================
        // 4. TAG: MeetingLogistics - Equipment & Support Staff
        // =========================================================================
        $materialsData = [
            ['name' => 'Owl Labs Meeting Owl 3 (360° Camera)', 'material_type' => 'video', 'total_quantity' => 4, 'quantity_available' => 3],
            ['name' => 'Jabra Speak 710 Wireless Speakerphone', 'material_type' => 'audio', 'total_quantity' => 6, 'quantity_available' => 5],
            ['name' => 'Logitech 4K ConferenceCam Bar', 'material_type' => 'video', 'total_quantity' => 3, 'quantity_available' => 2],
            ['name' => 'Magnetic Mobile Whiteboard & Color Marker Set', 'material_type' => 'stationery', 'total_quantity' => 8, 'quantity_available' => 8],
            ['name' => 'Wireless Presenter & Laser Pointer', 'material_type' => 'audio_visual', 'total_quantity' => 10, 'quantity_available' => 9],
            ['name' => 'Executive Catering Coffee & Pastry Set', 'material_type' => 'catering', 'total_quantity' => 5, 'quantity_available' => 4],
        ];

        $materialMap = [];
        foreach ($materialsData as $m) {
            $existing = DB::table('meeting_materials')
                ->where('tenant_id', $tenantId)
                ->where('name', $m['name'])
                ->first();

            if ($existing) {
                $matId = $existing->id;
                DB::table('meeting_materials')->where('id', $matId)->update(array_merge($m, ['updated_at' => $now]));
            } else {
                $matId = DB::table('meeting_materials')->insertGetId(array_merge($m, [
                    'uuid'           => (string) Str::uuid(),
                    'tenant_id'      => $tenantId,
                    'room_id'        => null,
                    'is_operational' => true,
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ]));
            }
            $materialMap[$m['name']] = $matId;
        }

        // Support Staff Roster
        $staffData = [
            ['name' => 'Vannak Som', 'role' => 'AV Support Technician', 'phone' => '+855 12 777 666', 'status' => 'active'],
            ['name' => 'Chhaya Sok', 'role' => 'Certified Meeting Facilitator & Scribe', 'phone' => '+855 12 555 444', 'status' => 'active'],
        ];

        $staffMap = [];
        foreach ($staffData as $s) {
            $existing = DB::table('meeting_staff')
                ->where('tenant_id', $tenantId)
                ->where('name', $s['name'])
                ->first();

            if ($existing) {
                $staffId = $existing->id;
                DB::table('meeting_staff')->where('id', $staffId)->update(array_merge($s, ['updated_at' => $now]));
            } else {
                $staffId = DB::table('meeting_staff')->insertGetId(array_merge($s, [
                    'tenant_id'  => $tenantId,
                    'user_id'    => $organizerId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]));
            }
            $staffMap[$s['name']] = $staffId;
        }

        // =========================================================================
        // 5. TAG: Meeting - Active, Upcoming & Completed Meetings
        // =========================================================================
        $today = $now->copy();

        $meetingsData = [
            // Meeting 1: Upcoming today in Grand Mekong Boardroom
            [
                'room_code'      => 'BD-401',
                'title'          => 'Executive Leadership & Q4 Strategy Review',
                'description'    => 'Quarterly executive review covering sales targets, expansion roadmap, and budget approvals.',
                'meeting_type'   => 'board_meeting',
                'start_time'     => $today->copy()->setTime(14, 0, 0),
                'end_time'       => $today->copy()->setTime(15, 30, 0),
                'status'         => 'scheduled',
                'virtual_link'   => 'https://meet.google.com/cbd-mms-exec',
                'checked_in_at'  => null,
                'attendees'      => [
                    ['name' => $orgAdmin?->name ?? 'Sunny Admin', 'email' => $orgAdmin?->email ?? 'admin@sunnycafe.kh', 'role' => 'organizer', 'rsvp' => 'accepted', 'user_id' => $organizerId],
                    ['name' => $cashier?->name ?? 'Dara Cashier', 'email' => $cashier?->email ?? 'cashier@sunnycafe.kh', 'role' => 'presenter', 'rsvp' => 'accepted', 'user_id' => $attendeeId],
                    ['name' => $superAdmin?->name ?? 'CodeBridges Admin', 'email' => $superAdmin?->email ?? 'codebridge2026@gmail.com', 'role' => 'attendee', 'rsvp' => 'tentative', 'user_id' => $superAdmin?->id],
                ],
                'material_alloc' => 'Logitech 4K ConferenceCam Bar',
                'staff_alloc'    => 'Vannak Som',
            ],
            // Meeting 2: In Progress Right Now in Tonle Sap Strategy Room
            [
                'room_code'      => 'BD-301',
                'title'          => 'Weekly Cross-Functional Operations Sync',
                'description'    => 'Operations, outlet inventory stock replenishment, and barista scheduling.',
                'meeting_type'   => 'operational_sync',
                'start_time'     => $now->copy()->subMinutes(20),
                'end_time'       => $now->copy()->addMinutes(40),
                'status'         => 'in_progress',
                'virtual_link'   => 'https://meet.google.com/cbd-ops-sync',
                'checked_in_at'  => $now->copy()->subMinutes(18),
                'attendees'      => [
                    ['name' => $orgAdmin?->name ?? 'Sunny Admin', 'email' => $orgAdmin?->email ?? 'admin@sunnycafe.kh', 'role' => 'organizer', 'rsvp' => 'accepted', 'user_id' => $organizerId],
                    ['name' => $cashier?->name ?? 'Dara Cashier', 'email' => $cashier?->email ?? 'cashier@sunnycafe.kh', 'role' => 'attendee', 'rsvp' => 'accepted', 'user_id' => $attendeeId],
                ],
                'material_alloc' => 'Jabra Speak 710 Wireless Speakerphone',
                'staff_alloc'    => null,
            ],
            // Meeting 3: Completed Earlier Today in Bokor Executive Huddle
            [
                'room_code'      => 'HD-201',
                'title'          => 'Product Design Sprint & Architecture Review',
                'description'    => 'Architecture review of K3s staging pipelines, POS BFF services, and NBC Bakong QR settlement.',
                'meeting_type'   => 'technical_review',
                'start_time'     => $today->copy()->setTime(9, 0, 0),
                'end_time'       => $today->copy()->setTime(10, 30, 0),
                'status'         => 'completed',
                'virtual_link'   => null,
                'checked_in_at'  => $today->copy()->setTime(8, 55, 0),
                'attendees'      => [
                    ['name' => $orgAdmin?->name ?? 'Sunny Admin', 'email' => $orgAdmin?->email ?? 'admin@sunnycafe.kh', 'role' => 'organizer', 'rsvp' => 'accepted', 'user_id' => $organizerId],
                    ['name' => $superAdmin?->name ?? 'CodeBridges Admin', 'email' => $superAdmin?->email ?? 'codebridge2026@gmail.com', 'role' => 'attendee', 'rsvp' => 'accepted', 'user_id' => $superAdmin?->id],
                ],
                'material_alloc' => 'Owl Labs Meeting Owl 3 (360° Camera)',
                'staff_alloc'    => 'Chhaya Sok',
            ],
            // Meeting 4: Scheduled Tomorrow in Angkor Innovation Lab
            [
                'room_code'      => 'LB-101',
                'title'          => 'Sunny Cafe Barista Quality & Customer Experience Workshop',
                'description'    => 'Hands-on espresso calibration, customer engagement best practices, and POS speed training.',
                'meeting_type'   => 'training',
                'start_time'     => $today->copy()->addDay()->setTime(10, 0, 0),
                'end_time'       => $today->copy()->addDay()->setTime(12, 0, 0),
                'status'         => 'scheduled',
                'virtual_link'   => null,
                'checked_in_at'  => null,
                'attendees'      => [
                    ['name' => $orgAdmin?->name ?? 'Sunny Admin', 'email' => $orgAdmin?->email ?? 'admin@sunnycafe.kh', 'role' => 'organizer', 'rsvp' => 'accepted', 'user_id' => $organizerId],
                    ['name' => $cashier?->name ?? 'Dara Cashier', 'email' => $cashier?->email ?? 'cashier@sunnycafe.kh', 'role' => 'attendee', 'rsvp' => 'accepted', 'user_id' => $attendeeId],
                ],
                'material_alloc' => null,
                'staff_alloc'    => null,
            ],
        ];

        $meetingIds = [];
        foreach ($meetingsData as $idx => $m) {
            $roomId = $roomMap[$m['room_code']] ?? null;
            if (!$roomId) continue;

            $existing = DB::table('meetings')
                ->where('tenant_id', $tenantId)
                ->where('room_id', $roomId)
                ->where('title', $m['title'])
                ->first();

            if ($existing) {
                $meetingId = $existing->id;
                DB::table('meetings')->where('id', $meetingId)->update([
                    'start_time'    => $m['start_time'],
                    'end_time'      => $m['end_time'],
                    'status'        => $m['status'],
                    'checked_in_at' => $m['checked_in_at'],
                    'updated_at'    => $now,
                ]);
            } else {
                $meetingId = DB::table('meetings')->insertGetId([
                    'uuid'          => (string) Str::uuid(),
                    'tenant_id'     => $tenantId,
                    'room_id'       => $roomId,
                    'organizer_id'  => $organizerId,
                    'series_id'     => null,
                    'title'         => $m['title'],
                    'description'   => $m['description'],
                    'meeting_type'  => $m['meeting_type'],
                    'start_time'    => $m['start_time'],
                    'end_time'      => $m['end_time'],
                    'status'        => $m['status'],
                    'virtual_link'  => $m['virtual_link'],
                    'meeting_link'  => $m['virtual_link'],
                    'checked_in_at' => $m['checked_in_at'],
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ]);
            }
            $meetingIds[$idx] = $meetingId;

            // Seed Attendees (meeting_attendees)
            foreach ($m['attendees'] as $att) {
                DB::table('meeting_attendees')->updateOrInsert(
                    ['meeting_id' => $meetingId, 'email' => $att['email']],
                    [
                        'user_id'          => $att['user_id'],
                        'name'             => $att['name'],
                        'role'             => $att['role'],
                        'rsvp_status'      => $att['rsvp'],
                        'checked_in_at'    => ($m['status'] === 'in_progress' || $m['status'] === 'completed') ? $m['checked_in_at'] : null,
                        'telegram_chat_id' => null,
                        'created_at'       => $now,
                        'updated_at'       => $now,
                    ]
                );
            }

            // Material Allocation
            if (!empty($m['material_alloc']) && isset($materialMap[$m['material_alloc']])) {
                $matId = $materialMap[$m['material_alloc']];
                DB::table('meeting_material_allocations')->updateOrInsert(
                    ['meeting_id' => $meetingId, 'material_id' => $matId],
                    [
                        'quantity_allocated' => 1,
                        'status'             => 'allocated',
                        'allocated_at'       => $m['start_time'],
                        'released_at'        => $m['status'] === 'completed' ? $m['end_time'] : null,
                        'created_at'         => $now,
                        'updated_at'         => $now,
                    ]
                );
            }

            // Staff Allocation
            if (!empty($m['staff_alloc']) && isset($staffMap[$m['staff_alloc']])) {
                $sId = $staffMap[$m['staff_alloc']];
                DB::table('meeting_staff_allocations')->updateOrInsert(
                    ['meeting_id' => $meetingId, 'staff_id' => $sId],
                    ['created_at' => $now, 'updated_at' => $now]
                );
            }
        }

        // =========================================================================
        // 6. TAG: MeetingMinutes - Minutes of Meeting (MoM)
        // =========================================================================
        $completedMeetingId = $meetingIds[2] ?? null; // Meeting 3
        if ($completedMeetingId) {
            $momMarkdown = <<<MD
# Minutes of Meeting: Product Design Sprint & Architecture Review

**Date**: Today, 09:00 - 10:30 AM  
**Location**: Bokor Executive Huddle (HD-201)  
**Chairperson**: Sunny Admin  
**Scribe**: Chhaya Sok  

## 1. Executive Summary
The engineering team conducted a comprehensive architectural review of our automated deployment pipeline and POS payment systems.

## 2. Key Discussion Points
1. **K3s Staging Deployment**: Evaluated the isolated per-service deployment scripts and confirmed zero downtime on rolling updates.
2. **NBC Bakong KHQR Reconciliation**: Verified instant webhook handling for real-time payment confirmation at the cashier POS terminal.
3. **Ghost-Meeting Auto-Release**: Approved the 15-minute unattended room cancellation threshold to maximize conference room utilization.

## 3. Formal Resolutions
* **Resolution 1**: Adopt K3s as the standard container runtime across staging environments (Unanimous).
* **Resolution 2**: Migrate legacy Telegram bot credentials to the unified `notification_providers` vault (Unanimous).
MD;

            $actionItemsJson = json_encode([
                ['task' => 'Finalize Q4 Cloud Budget Forecast', 'assignee' => 'Sunny Admin', 'due' => '7 days'],
                ['task' => 'Conduct POS Terminal Barcode Scanner stress test', 'assignee' => 'Dara Cashier', 'due' => '3 days'],
                ['task' => 'Update K3s Staging Runbook documentation', 'assignee' => 'Sunny Admin', 'due' => '1 day'],
            ]);

            DB::table('meeting_minutes')->updateOrInsert(
                ['meeting_id' => $completedMeetingId],
                [
                    'author_id'        => $organizerId,
                    'recorded_by'      => $organizerId,
                    'content_markdown' => $momMarkdown,
                    'content'          => 'Minutes approved by chairperson. Resolutions adopted unanimously.',
                    'action_items'     => $actionItemsJson,
                    'status'           => 'published',
                    'is_published'     => true,
                    'published_at'     => $now,
                    'created_at'       => $now,
                    'updated_at'       => $now,
                ]
            );
        }

        // =========================================================================
        // 7. TAG: MeetingActionItem - Post-Meeting Assigned Tasks
        // =========================================================================
        if ($completedMeetingId) {
            $actionItems = [
                [
                    'assignee_id'      => $organizerId,
                    'task_description' => 'Finalize Q4 Cloud Infrastructure Budget Forecast Spreadsheet',
                    'due_date'         => $now->copy()->addDays(7)->toDateString(),
                    'status'           => 'in_progress',
                    'priority'         => 'high',
                    'completed_at'     => null,
                ],
                [
                    'assignee_id'      => $attendeeId,
                    'task_description' => 'Conduct POS Terminal Barcode Scanner & Bakong KHQR stress test',
                    'due_date'         => $now->copy()->addDays(3)->toDateString(),
                    'status'           => 'pending',
                    'priority'         => 'urgent',
                    'completed_at'     => null,
                ],
                [
                    'assignee_id'      => $organizerId,
                    'task_description' => 'Update K3s Staging Deployment Documentation and Runbook',
                    'due_date'         => $now->copy()->addDays(1)->toDateString(),
                    'status'           => 'completed',
                    'priority'         => 'medium',
                    'completed_at'     => $now,
                ],
            ];

            foreach ($actionItems as $item) {
                DB::table('meeting_action_items')->updateOrInsert(
                    ['meeting_id' => $completedMeetingId, 'task_description' => $item['task_description']],
                    array_merge($item, ['created_at' => $now, 'updated_at' => $now])
                );
            }
        }

        // =========================================================================
        // 8. TAG: MeetingIssue - Facility Defect Reporting
        // =========================================================================
        $issues = [
            [
                'room_code'         => 'BD-401',
                'issue_description' => 'Ceiling HDMI Projector cable is flickering intermittently when connected to MacBook Pro.',
                'severity'          => 'high',
                'status'            => 'investigating',
                'resolved_at'       => null,
            ],
            [
                'room_code'         => 'BD-301',
                'issue_description' => 'Whiteboard marker ink running low, whiteboard eraser needs replacement.',
                'severity'          => 'low',
                'status'            => 'resolved',
                'resolved_at'       => $now,
            ],
        ];

        foreach ($issues as $iss) {
            $roomId = $roomMap[$iss['room_code']] ?? null;
            if (!$roomId) continue;

            DB::table('meeting_room_issues')->updateOrInsert(
                ['tenant_id' => $tenantId, 'room_id' => $roomId, 'issue_description' => $iss['issue_description']],
                [
                    'reported_by'  => $organizerId,
                    'severity'     => $iss['severity'],
                    'status'       => $iss['status'],
                    'resolved_at'  => $iss['resolved_at'],
                    'created_at'   => $now,
                    'updated_at'   => $now,
                ]
            );
        }

        // =========================================================================
        // 9. TAG: MeetingNotification - In-App Activity & Reminder Badges
        // =========================================================================
        $notifications = [
            [
                'user_id'    => $organizerId,
                'meeting_id' => $meetingIds[0] ?? null,
                'type'       => 'reminder',
                'title'      => 'Meeting Starting Soon: Executive Leadership & Q4 Strategy Review',
                'message'    => 'Your meeting in Grand Mekong Boardroom starts at 14:00 today. Please check in on the door tablet.',
                'status'     => 'unread',
            ],
            [
                'user_id'    => $attendeeId,
                'meeting_id' => $meetingIds[0] ?? null,
                'type'       => 'invitation',
                'title'      => 'Meeting Invitation: Executive Leadership & Q4 Strategy Review',
                'message'    => 'You have been invited by Sunny Admin as a presenter for the Q4 Strategy Review.',
                'status'     => 'read',
            ],
            [
                'user_id'    => $organizerId,
                'meeting_id' => $completedMeetingId,
                'type'       => 'action_item',
                'title'      => 'New Action Item Assigned: Finalize Q4 Cloud Budget Forecast',
                'message'    => 'Action item assigned from Product Design Sprint. Due in 7 days.',
                'status'     => 'read',
            ],
        ];

        foreach ($notifications as $notif) {
            DB::table('meeting_notifications')->updateOrInsert(
                ['tenant_id' => $tenantId, 'user_id' => $notif['user_id'], 'title' => $notif['title']],
                array_merge($notif, ['created_at' => $now, 'updated_at' => $now])
            );
        }

        // =========================================================================
        // 10. TAG: MeetingTelegram - Bot Configurations & Dynamic Templates
        // =========================================================================
        DB::table('meeting_telegram_configs')->updateOrInsert(
            ['tenant_id' => $tenantId],
            [
                'bot_token'  => '8123456789:AAH_DEMO_MMS_TELEGRAM_BOT_TOKEN_2026',
                'chat_id'    => '-1002345678901',
                'is_active'  => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        $templates = [
            [
                'template_type' => 'meeting_created',
                'subject'       => 'New Meeting Booked',
                'body'          => "📅 *NEW MEETING BOOKED*\n━━━━━━━━━━━━━━━━━━━━━\n📌 *Title:* {title}\n🏢 *Room:* {room}\n⏰ *Time:* {time}\n👤 *Organizer:* {organizer}\n━━━━━━━━━━━━━━━━━━━━━\nPlease arrive 5 minutes early for AV preparation.",
                'channel'       => 'telegram',
            ],
            [
                'template_type' => 'meeting_cancelled',
                'subject'       => 'Meeting Cancelled',
                'body'          => "❌ *MEETING CANCELLED*\n━━━━━━━━━━━━━━━━━━━━━\n📌 *Title:* {title}\n🏢 *Room:* {room}\n⏰ *Original Time:* {time}\nℹ️ *Reason:* {reason}\n━━━━━━━━━━━━━━━━━━━━━\nThe room is now available for other teams.",
                'channel'       => 'telegram',
            ],
            [
                'template_type' => 'meeting_reminder',
                'subject'       => 'Meeting Reminder (15 min)',
                'body'          => "⏰ *UPCOMING MEETING IN 15 MINUTES*\n━━━━━━━━━━━━━━━━━━━━━\n📌 *Title:* {title}\n🏢 *Room:* {room}\n⏰ *Starts At:* {time}\n👤 *Organizer:* {organizer}\n━━━━━━━━━━━━━━━━━━━━━\nPlease tap 'Check In' on the room tablet upon arrival.",
                'channel'       => 'telegram',
            ],
        ];

        foreach ($templates as $tmpl) {
            DB::table('meeting_notification_templates')->updateOrInsert(
                ['tenant_id' => $tenantId, 'template_type' => $tmpl['template_type']],
                array_merge($tmpl, ['created_at' => $now, 'updated_at' => $now])
            );
        }
    }
}
