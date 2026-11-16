<?php

namespace Tests\Feature;

use App\Models\Auth\PasskeyCredential;
use App\Models\Auth\RefreshToken;
use App\Models\Auth\User;
use App\Models\Auth\UserInvitation;
use App\Models\Auth\UserPosPin;
use App\Models\Auth\UserSession;
use App\Models\Crm\Customer;
use App\Models\Crm\DigitalVoucher;
use App\Models\Crm\StoreCreditInvoice;
use App\Models\Meeting\Meeting;
use App\Models\Meeting\MeetingActionItem;
use App\Models\Meeting\MeetingAttachment;
use App\Models\Meeting\MeetingAttendee;
use App\Models\Meeting\MeetingCateringOrder;
use App\Models\Meeting\MeetingFeedback;
use App\Models\Meeting\MeetingMaterial;
use App\Models\Meeting\MeetingMaterialAllocation;
use App\Models\Meeting\MeetingMinute;
use App\Models\Meeting\MeetingNotification;
use App\Models\Meeting\MeetingNotificationTemplate;
use App\Models\Meeting\MeetingRoom;
use App\Models\Meeting\MeetingRoomDisplay;
use App\Models\Meeting\MeetingRoomIssue;
use App\Models\Meeting\MeetingSeries;
use App\Models\Meeting\MeetingStaff;
use App\Models\Meeting\MeetingStaffAllocation;
use App\Models\Meeting\MeetingSystemSetting;
use App\Models\Meeting\MeetingTelegramConfig;
use App\Models\Meeting\MeetingTelegramGroup;
use App\Models\Meeting\MeetingTelegramRoute;
use App\Models\Organization\Department;
use App\Models\Organization\Employee;
use App\Models\Organization\Outlet;
use App\Models\Organization\Supplier;
use App\Models\Organization\Tenant;
use App\Models\Organization\TenantModule;
use App\Models\Organization\TenantSubscription;
use App\Models\Organization\TenantUser;
use App\Models\Organization\TenantUserOutlet;
use App\Models\Security\ApiClient;
use App\Models\Security\ApiKey;
use App\Models\Security\ApiRequestLog;
use App\Models\Security\AuditLog;
use App\Models\Security\NotificationProvider;
use App\Models\Security\SecurityEvent;
use App\Models\System\Permission;
use App\Models\System\Role;
use App\Models\System\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AllModelsCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_48_models_can_be_created_queried_and_verified_in_database(): void
    {
        // -------------------------------------------------------------
        // 1. SYSTEM DOMAIN (3 models)
        // -------------------------------------------------------------
        $role = Role::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Auditor Role',
            'slug' => 'auditor-' . uniqid(),
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('roles', ['id' => $role->id]);

        $permission = Permission::create([
            'name' => 'Audit Reports',
            'slug' => 'audit.reports.' . uniqid(),
            'module_id' => 1,
            'group' => 'compliance',
            'description' => 'Can audit platform data',
        ]);
        $this->assertDatabaseHas('permissions', ['id' => $permission->id]);

        // -------------------------------------------------------------
        // 2. ORGANIZATION DOMAIN (Core Tenant & Outlets - 9 models)
        // -------------------------------------------------------------
        $user = User::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'CRUD Test User',
            'email' => 'crud_' . uniqid() . '@example.com',
            'password' => bcrypt('Password123!'),
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('users', ['id' => $user->id]);

        $userRole = UserRole::create([
            'user_id' => $user->id,
            'role_id' => $role->id,
        ]);
        $this->assertDatabaseHas('user_roles', ['user_id' => $user->id, 'role_id' => $role->id]);

        $tenant = Tenant::create([
            'uuid' => (string) Str::uuid(),
            'owner_user_id' => $user->id,
            'name' => 'CRUD Organization',
            'business_type' => 'Retail',
            'slug' => 'crud-org-' . uniqid(),
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('tenants', ['id' => $tenant->id]);

        $outlet = Outlet::create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'name' => 'Main Test Outlet',
            'code' => 'OUT-' . strtoupper(Str::random(4)),
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('outlets', ['id' => $outlet->id]);

        $dept = Department::create([
            'tenant_id' => $tenant->id,
            'name' => 'Engineering',
            'code' => 'DEPT-ENG',
            'description' => 'Core Platform Engineering',
        ]);
        $this->assertDatabaseHas('departments', ['id' => $dept->id]);

        $employee = Employee::create([
            'tenant_id' => $tenant->id,
            'department_id' => $dept->id,
            'user_id' => $user->id,
            'employee_code' => 'EMP-001',
            'first_name' => 'John',
            'last_name' => 'Tester',
            'email' => 'john.tester@example.com',
            'designation' => 'QA Lead',
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('employees', ['id' => $employee->id]);

        $supplier = Supplier::create([
            'tenant_id' => $tenant->id,
            'name' => 'Prime Hardware Supplies',
            'company_name' => 'Prime Hardware LLC',
            'phone' => '012345678',
            'email' => 'contact@primehardware.kh',
            'address' => 'Phnom Penh, Cambodia',
        ]);
        $this->assertDatabaseHas('suppliers', ['id' => $supplier->id]);

        $tenantUser = TenantUser::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $this->assertDatabaseHas('tenant_users', ['id' => $tenantUser->id]);

        $tenantUserOutlet = TenantUserOutlet::create([
            'tenant_user_id' => $tenantUser->id,
            'outlet_uuid' => $outlet->uuid,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('tenant_user_outlets', ['id' => $tenantUserOutlet->id]);

        \Illuminate\Support\Facades\DB::table('platform_modules')->insertOrIgnore([
            'id' => 1,
            'name' => 'POS Module',
            'category' => 'Sales',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $tenantModule = TenantModule::create([
            'tenant_id' => $tenant->id,
            'module_id' => 1,
            'is_active' => true,
            'activated_by' => $user->id,
            'activated_at' => now(),
        ]);
        $this->assertDatabaseHas('tenant_modules', ['id' => $tenantModule->id]);

        $subscription = TenantSubscription::create([
            'tenant_id' => $tenant->id,
            'plan_name' => 'Enterprise Suite',
            'billing_cycle' => 'monthly',
            'price' => 99.00,
            'currency' => 'USD',
            'status' => 'active',
            'starts_at' => now(),
            'expires_at' => now()->addMonth(),
        ]);
        $this->assertDatabaseHas('tenant_subscriptions', ['id' => $subscription->id]);

        // -------------------------------------------------------------
        // 3. SECURITY & AUTH DOMAIN (API Clients, Sessions, Tokens, Credentials)
        // -------------------------------------------------------------
        $apiClient = ApiClient::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Automated Integration Client',
            'client_key' => 'client_test_' . uniqid(),
            'client_type' => 'confidential',
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('api_clients', ['id' => $apiClient->id]);

        $session = UserSession::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'api_client_id' => $apiClient->id,
            'tenant_id' => $tenant->id,
            'device_uuid' => (string) Str::uuid(),
            'device_name' => 'CI Test Agent',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit/11.5',
            'permissions' => ['*'],
            'expires_at' => now()->addDays(7),
        ]);
        $this->assertDatabaseHas('user_sessions', ['id' => $session->id]);

        $refreshToken = RefreshToken::create([
            'session_id' => $session->id,
            'token_id' => Str::random(32),
            'token_hash' => hash('sha256', Str::random(40)),
            'expires_at' => now()->addDays(7),
            'created_at' => now(),
        ]);
        $this->assertDatabaseHas('refresh_tokens', ['id' => $refreshToken->id]);

        $passkey = PasskeyCredential::create([
            'user_id' => $user->id,
            'credential_id' => base64_encode(Str::random(32)),
            'user_handle' => (string) Str::uuid(),
            'public_key' => base64_encode(Str::random(64)),
            'sign_count' => 0,
            'name' => 'MacBook TouchID',
        ]);
        $this->assertDatabaseHas('passkey_credentials', ['id' => $passkey->id]);

        $invitation = UserInvitation::create([
            'tenant_id' => $tenant->id,
            'email' => 'invitee_' . uniqid() . '@example.com',
            'role_id' => $role->id,
            'invited_by' => $user->id,
            'token_hash' => hash('sha256', Str::random(40)),
            'expires_at' => now()->addDays(7),
        ]);
        $this->assertDatabaseHas('user_invitations', ['id' => $invitation->id]);

        $posPin = UserPosPin::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'pin_hash' => hash('sha256', '1234'),
            'is_active' => true,
            'failed_attempts' => 0,
        ]);
        $this->assertDatabaseHas('user_pos_pins', ['id' => $posPin->id]);

        // -------------------------------------------------------------
        // 4. SECURITY DOMAIN (API Keys, Providers, Events, Logs - 5 models)
        // -------------------------------------------------------------

        $apiKey = ApiKey::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'name' => 'Developer Key',
            'key_prefix' => 'cb_',
            'key' => 'cb_' . Str::random(32),
            'secret' => hash('sha256', 'secret'),
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('api_keys', ['id' => $apiKey->id]);

        $notificationProvider = NotificationProvider::create([
            'tenant_id' => $tenant->id,
            'name' => 'Alerts Telegram Bot',
            'channel' => 'telegram',
            'provider' => 'telegram_bot',
            'environment' => 'production',
            'credentials' => '12345:TOKEN',
            'is_enabled' => true,
            'settings' => ['bot_token' => '12345:TOKEN'],
        ]);
        $this->assertDatabaseHas('notification_providers', ['id' => $notificationProvider->id]);

        $securityEvent = SecurityEvent::create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'event_type' => 'suspicious_login_blocked',
            'severity' => 'medium',
            'ip_address' => '192.0.2.1',
            'summary' => 'Blocked login attempt from blacklisted IP',
            'created_at' => now(),
        ]);
        $this->assertDatabaseHas('security_events', ['id' => $securityEvent->id]);

        $apiRequestLog = ApiRequestLog::create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'request_id' => (string) Str::uuid(),
            'method' => 'GET',
            'path' => '/api/v1/health',
            'status_code' => 200,
            'ip_address' => '127.0.0.1',
            'created_at' => now(),
        ]);
        $this->assertDatabaseHas('api_request_logs', ['id' => $apiRequestLog->id]);

        $auditLog = AuditLog::create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'action' => 'tenant.updated',
            'module' => 'organization',
            'request_id' => (string) Str::uuid(),
        ]);
        $this->assertDatabaseHas('audit_logs', ['id' => $auditLog->id]);

        // -------------------------------------------------------------
        // 5. CRM DOMAIN (3 models)
        // -------------------------------------------------------------
        $customer = Customer::create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'code' => 'CUST-' . strtoupper(Str::random(5)),
            'name' => 'Alice Customer',
            'email' => 'alice@testcrm.com',
            'phone' => '0987654321',
            'store_credit_balance' => 150.00,
            'loyalty_points' => 300,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('customers', ['id' => $customer->id]);

        $voucher = DigitalVoucher::create([
            'tenant_id' => $tenant->id,
            'voucher_code' => 'VOUCH-' . strtoupper(Str::random(8)),
            'title' => 'Holiday Promotion $25',
            'value' => 25.00,
            'currency' => 'USD',
            'status' => 'active',
            'expires_at' => now()->addDays(30),
        ]);
        $this->assertDatabaseHas('digital_vouchers', ['id' => $voucher->id]);

        $creditInvoice = StoreCreditInvoice::create([
            'tenant_id' => $tenant->id,
            'invoice_number' => 'SCI-' . strtoupper(Str::random(8)),
            'customer_id' => $customer->id,
            'amount_paid' => 100.00,
            'credit_amount' => 100.00,
            'payment_method' => 'bakong_khqr',
            'status' => 'paid',
        ]);
        $this->assertDatabaseHas('store_credit_invoices', ['id' => $creditInvoice->id]);

        // -------------------------------------------------------------
        // 6. MEETING MANAGEMENT SYSTEM (MMS) DOMAIN (21 models)
        // -------------------------------------------------------------
        $room = MeetingRoom::create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'name' => 'Grand Executive Boardroom',
            'code' => 'ROOM-' . strtoupper(Str::random(4)),
            'location' => 'Building A',
            'floor' => 'Level 5',
            'capacity' => 18,
            'hourly_rate' => 45.00,
            'currency' => 'USD',
            'is_active' => true,
            'amenities' => ['video_conferencing', 'whiteboard', 'surround_sound'],
        ]);
        $this->assertDatabaseHas('meeting_rooms', ['id' => $room->id]);

        $series = MeetingSeries::create([
            'tenant_id' => $tenant->id,
            'organizer_id' => $user->id,
            'title' => 'Weekly Architecture Review',
            'timezone' => 'UTC',
            'recurrence_rule' => 'FREQ=WEEKLY;BYDAY=MO',
            'starts_at' => now()->addDay(),
            'until_at' => now()->addMonths(3),
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('meeting_series', ['id' => $series->id]);

        $meeting = Meeting::create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'room_id' => $room->id,
            'organizer_id' => $user->id,
            'series_id' => $series->id,
            'title' => 'Q4 Roadmap & Infrastructure Sync',
            'description' => 'Review multi-service scale and resilience',
            'status' => 'scheduled',
            'start_time' => now()->addHours(2),
            'end_time' => now()->addHours(4),
            'meeting_type' => 'hybrid',
            'meeting_link' => 'https://meet.codebridges.com/arch-sync',
        ]);
        $this->assertDatabaseHas('meetings', ['id' => $meeting->id]);

        $attendee = MeetingAttendee::create([
            'meeting_id' => $meeting->id,
            'user_id' => $user->id,
            'name' => 'Lead Engineer',
            'email' => 'lead.eng@codebridge.kh',
            'role' => 'chairperson',
            'rsvp_status' => 'accepted',
            'telegram_chat_id' => '12345678',
        ]);
        $this->assertDatabaseHas('meeting_attendees', ['id' => $attendee->id]);

        $material = MeetingMaterial::create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'room_id' => $room->id,
            'name' => 'Poly Studio 4K Camera',
            'material_type' => 'av_equipment',
            'total_quantity' => 2,
            'quantity_available' => 2,
            'is_operational' => true,
        ]);
        $this->assertDatabaseHas('meeting_materials', ['id' => $material->id]);

        $matAlloc = MeetingMaterialAllocation::create([
            'meeting_id' => $meeting->id,
            'material_id' => $material->id,
            'quantity_allocated' => 1,
            'status' => 'allocated',
            'allocated_at' => now(),
        ]);
        $this->assertDatabaseHas('meeting_material_allocations', ['id' => $matAlloc->id]);

        $minute = MeetingMinute::create([
            'meeting_id' => $meeting->id,
            'author_id' => $user->id,
            'recorded_by' => $user->id,
            'content_markdown' => '# Meeting Minutes\n\n- Discussed database sharding and indexes.\n- Approved 2026 plan.',
            'content' => 'Discussed database sharding and indexes. Approved 2026 plan.',
            'status' => 'published',
            'is_published' => true,
            'published_at' => now(),
        ]);
        $this->assertDatabaseHas('meeting_minutes', ['id' => $minute->id]);

        $actionItem = MeetingActionItem::create([
            'meeting_id' => $meeting->id,
            'assignee_id' => $user->id,
            'task_description' => 'Deploy high-availability Redis Sentinel cluster',
            'due_date' => now()->addDays(7)->toDateString(),
            'status' => 'in_progress',
            'priority' => 'critical',
        ]);
        $this->assertDatabaseHas('meeting_action_items', ['id' => $actionItem->id]);

        $attachment = MeetingAttachment::create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'meeting_id' => $meeting->id,
            'file_name' => 'cloud_architecture_2026.pdf',
            'file_path' => 'meetings/attachments/cloud_architecture_2026.pdf',
            'file_type' => 'pdf',
            'file_size' => 4194304,
        ]);
        $this->assertDatabaseHas('meeting_attachments', ['id' => $attachment->id]);

        $catering = MeetingCateringOrder::create([
            'meeting_id' => $meeting->id,
            'package_name' => 'Premium Barista Coffee & Pastries',
            'quantity' => 15,
            'unit_cost' => 3.50,
            'total_cost' => 52.50,
            'currency' => 'USD',
            'delivery_time' => now()->addHours(2),
            'status' => 'confirmed',
        ]);
        $this->assertDatabaseHas('meeting_catering_orders', ['id' => $catering->id]);

        $feedback = MeetingFeedback::create([
            'meeting_id' => $meeting->id,
            'attendee_id' => $attendee->id,
            'rating_stars' => 5,
            'feedback_comment' => 'Audio clarity was exceptional.',
            'submitted_at' => now(),
        ]);
        $this->assertDatabaseHas('meeting_feedback', ['id' => $feedback->id]);

        $issue = MeetingRoomIssue::create([
            'tenant_id' => $tenant->id,
            'room_id' => $room->id,
            'reported_by' => $user->id,
            'issue_description' => 'Right wireless microphone battery low',
            'severity' => 'low',
            'status' => 'open',
        ]);
        $this->assertDatabaseHas('meeting_room_issues', ['id' => $issue->id]);

        $staff = MeetingStaff::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'name' => 'Somnang AV Tech',
            'role' => 'Audio Visual Technician',
            'phone' => '012998877',
            'status' => 'available',
        ]);
        $this->assertDatabaseHas('meeting_staff', ['id' => $staff->id]);

        $staffAlloc = MeetingStaffAllocation::create([
            'meeting_id' => $meeting->id,
            'staff_id' => $staff->id,
        ]);
        $this->assertDatabaseHas('meeting_staff_allocations', ['id' => $staffAlloc->id]);

        $setting = MeetingSystemSetting::create([
            'tenant_id' => $tenant->id,
            'setting_key' => 'buffer_time_minutes',
            'setting_value' => '15',
            'category' => 'scheduling',
            'data_type' => 'integer',
            'display_name' => 'Buffer Between Meetings',
            'is_public' => true,
        ]);
        $this->assertDatabaseHas('meeting_system_settings', ['id' => $setting->id]);

        $tgConfig = MeetingTelegramConfig::create([
            'tenant_id' => $tenant->id,
            'bot_token' => '987654321:AAEEXAMPLE_BOT_TOKEN',
            'chat_id' => '-1001234567890',
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('meeting_telegram_configs', ['id' => $tgConfig->id]);

        $tgGroup = MeetingTelegramGroup::create([
            'notification_provider_id' => $notificationProvider->id,
            'telegram_chat_id' => '-1009876543210',
            'tenant_id' => $tenant->id,
            'chat_title' => 'Executive Board Announcements',
            'chat_type' => 'supergroup',
            'link_status' => 'linked',
            'linked_at' => now(),
        ]);
        $this->assertDatabaseHas('meeting_telegram_groups', ['id' => $tgGroup->id]);

        $tgRoute = MeetingTelegramRoute::create([
            'tenant_id' => $tenant->id,
            'telegram_config_id' => $tgConfig->id,
            'telegram_group_id' => $tgGroup->id,
            'event_type' => 'meeting.starting',
            'is_enabled' => true,
        ]);
        $this->assertDatabaseHas('meeting_telegram_routes', ['id' => $tgRoute->id]);

        $template = MeetingNotificationTemplate::create([
            'tenant_id' => $tenant->id,
            'template_type' => 'meeting_15m_reminder',
            'subject' => 'Meeting Starting in 15 Minutes: {{title}}',
            'body' => 'Your meeting {{title}} in {{room}} starts in 15 minutes. Join link: {{link}}',
            'channel' => 'telegram',
        ]);
        $this->assertDatabaseHas('meeting_notification_templates', ['id' => $template->id]);

        $notification = MeetingNotification::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'meeting_id' => $meeting->id,
            'type' => 'start_reminder',
            'title' => 'Meeting Imminent',
            'message' => 'Q4 Roadmap meeting is about to begin.',
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('meeting_notifications', ['id' => $notification->id]);

        $display = MeetingRoomDisplay::create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'room_id' => $room->id,
            'device_token_hash' => hash('sha256', Str::random(32)),
            'screen_pin_hash' => hash('sha256', '5678'),
            'display_name' => 'Room 501 Door Tablet',
            'status' => 'online',
            'last_ping_at' => now(),
            'failed_attempts' => 0,
        ]);
        $this->assertDatabaseHas('meeting_room_displays', ['id' => $display->id]);

        // -------------------------------------------------------------
        // 7. VERIFY ELOQUENT RELATIONSHIPS ACROSS DOMAINS
        // -------------------------------------------------------------
        $loadedMeeting = Meeting::with(['room', 'organizer', 'series', 'attendees', 'attachments', 'materialAllocations', 'minutes', 'actionItems'])->find($meeting->id);
        $this->assertNotNull($loadedMeeting);
        $this->assertEquals($room->id, $loadedMeeting->room->id);
        $this->assertEquals($user->id, $loadedMeeting->organizer->id);
        $this->assertEquals($series->id, $loadedMeeting->series->id);
        $this->assertCount(1, $loadedMeeting->attendees);
        $this->assertCount(1, $loadedMeeting->attachments);
        $this->assertCount(1, $loadedMeeting->materialAllocations);
        $this->assertNotNull($loadedMeeting->minutes);
        $this->assertCount(1, $loadedMeeting->actionItems);

        $loadedRoom = MeetingRoom::with(['tenant', 'meetings', 'materials', 'issues', 'displays'])->find($room->id);
        $this->assertNotNull($loadedRoom);
        $this->assertEquals($tenant->id, $loadedRoom->tenant->id);
        $this->assertCount(1, $loadedRoom->meetings);
        $this->assertCount(1, $loadedRoom->materials);
        $this->assertCount(1, $loadedRoom->issues);
        $this->assertCount(1, $loadedRoom->displays);

        $loadedUser = User::with(['roles', 'outlet', 'userSessions', 'securityEvents', 'organizedMeetings'])->find($user->id);
        $this->assertNotNull($loadedUser);
        $this->assertCount(1, $loadedUser->roles);
        $this->assertCount(1, $loadedUser->userSessions);
        $this->assertCount(1, $loadedUser->securityEvents);
        $this->assertCount(1, $loadedUser->organizedMeetings);
    }
}
