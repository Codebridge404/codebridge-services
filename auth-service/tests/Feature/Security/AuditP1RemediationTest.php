<?php

namespace Tests\Feature\Security;

use App\Models\Outlet;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\JwtService;
use App\Services\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AuditP1RemediationTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(string $slug, string $status = 'active'): Tenant
    {
        return Tenant::create([
            'name' => ucfirst($slug) . ' Org',
            'slug' => $slug,
            'status' => $status,
            'email' => "{$slug}@example.com",
            'currency' => 'USD',
            'client_tier' => 'business_runner',
        ]);
    }

    private function outlet(Tenant $tenant, string $name = 'Branch 1'): Outlet
    {
        return Outlet::create([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'code' => 'OUT-' . bin2hex(random_bytes(3)),
            'status' => 'active',
            'is_active' => true,
        ]);
    }

    private function user(Tenant $tenant, string $role = 'admin', bool $isActive = true, ?Outlet $outlet = null): User
    {
        $roleId = DB::table('roles')->where('slug', $role)->value('id') ?? DB::table('roles')->insertGetId([
            'slug' => $role,
            'name' => ucfirst($role),
            'parent_role' => in_array($role, ['admin', 'organization', 'owner']) ? 'organization' : ($role === 'super_admin' ? 'super_admin' : 'employee'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::factory()->create([
            'role' => $role,
            'tenant_id' => $tenant->id,
            'is_active' => $isActive,
            'password' => Hash::make('Password123!'),
        ]);

        DB::table('tenant_users')->updateOrInsert(
            ['tenant_id' => $tenant->id, 'user_id' => $user->id],
            ['status' => $isActive ? 'active' : 'inactive', 'created_at' => now(), 'updated_at' => now()]
        );

        DB::table('user_roles')->updateOrInsert(
            ['user_id' => $user->id, 'role_id' => $roleId],
            ['tenant_id' => $tenant->id, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]
        );

        if ($outlet) {
            $tuId = DB::table('tenant_users')->where('tenant_id', $tenant->id)->where('user_id', $user->id)->value('id');
            if ($tuId) {
                DB::table('tenant_user_outlets')->updateOrInsert(
                    ['tenant_user_id' => $tuId, 'outlet_uuid' => $outlet->uuid],
                    ['is_active' => true, 'created_at' => now(), 'updated_at' => now()]
                );
            }
        }

        return $user;
    }

    private function bearer(User $user): static
    {
        Auth::forgetGuards();
        $this->app->forgetInstance('auth.driver');
        $token = app(JwtService::class)->generateTokenForUser($user);
        return $this->withHeader('Authorization', 'Bearer ' . $token);
    }

    /**
     * P1 Finding 1: Outlet Manager privilege escalation prevention.
     */
    public function test_outlet_manager_cannot_invite_admin_or_reset_admin_password(): void
    {
        $tenant = $this->tenant('om-test');
        $outlet1 = $this->outlet($tenant, 'Outlet 1');
        $outlet2 = $this->outlet($tenant, 'Outlet 2');

        $manager = $this->user($tenant, 'outlet_manager', true, $outlet1);
        $admin = $this->user($tenant, 'admin', true, $outlet1);
        $cashierOutlet1 = $this->user($tenant, 'cashier', true, $outlet1);
        $cashierOutlet2 = $this->user($tenant, 'cashier', true, $outlet2);

        // 1. Outlet manager cannot invite an administrator
        $inviteRes = $this->bearer($manager)->postJson('/api/v1/invitations', [
            'email' => 'newadmin@om-test.com',
            'role' => 'admin',
            'outlet_id' => $outlet1->id,
        ]);
        $inviteRes->assertStatus(403);

        // 2. Outlet manager cannot invite staff to another outlet
        $inviteForeignOutletRes = $this->bearer($manager)->postJson('/api/v1/invitations', [
            'email' => 'staff2@om-test.com',
            'role' => 'cashier',
            'outlet_id' => $outlet2->id,
        ]);
        $inviteForeignOutletRes->assertStatus(403);

        // 3. Outlet manager CAN invite subordinate staff to their own outlet
        $inviteSubordinateRes = $this->bearer($manager)->postJson('/api/v1/invitations', [
            'email' => 'subordinate@om-test.com',
            'role' => 'cashier',
            'outlet_id' => $outlet1->id,
        ]);
        $inviteSubordinateRes->assertStatus(201);

        // 4. Outlet manager cannot reset tenant admin password
        $resetAdminRes = $this->bearer($manager)->postJson("/api/v1/users/{$admin->id}/reset-password", [
            'new_password' => 'HackedAdmin123!',
        ]);
        $resetAdminRes->assertStatus(403);

        // 5. Outlet manager cannot reset password of staff in another outlet
        $resetForeignStaffRes = $this->bearer($manager)->postJson("/api/v1/users/{$cashierOutlet2->id}/reset-password", [
            'new_password' => 'HackedCashier123!',
        ]);
        $resetForeignStaffRes->assertStatus(403);

        // 6. Outlet manager CAN reset password of subordinate in same outlet
        $resetSameOutletRes = $this->bearer($manager)->postJson("/api/v1/users/{$cashierOutlet1->id}/reset-password", [
            'new_password' => 'NewCashierPass123!',
        ]);
        $resetSameOutletRes->assertStatus(200);
    }

    /**
     * P1 Finding 2: POST /users/attach does not reactivate suspended accounts.
     */
    public function test_users_attach_does_not_reactivate_globally_suspended_user(): void
    {
        $tenant1 = $this->tenant('attach-t1');
        $tenant2 = $this->tenant('attach-t2');
        $admin = $this->user($tenant1, 'admin');

        $suspendedUser = User::factory()->create([
            'role' => 'employee',
            'is_active' => false,
            'email' => 'suspended@example.com',
            'password' => Hash::make('password'),
        ]);

        // Attempting to attach suspended account by non-super-admin is rejected
        $attachRes = $this->bearer($admin)->postJson('/api/v1/users/attach', [
            'user_id' => $suspendedUser->id,
            'role' => 'cashier',
        ]);
        $attachRes->assertStatus(403);

        // Verify global account status remains inactive
        $this->assertFalse((bool) DB::table('users')->where('id', $suspendedUser->id)->value('is_active'));
    }

    /**
     * P1 Finding 3: Inactive users and suspended tenants blocked on Sanctum and Workspace Guard.
     */
    public function test_inactive_user_and_suspended_tenant_blocked(): void
    {
        $activeTenant = $this->tenant('active-t');
        $suspendedTenant = $this->tenant('suspended-t', 'suspended');

        $inactiveUser = $this->user($activeTenant, 'cashier', false);
        $userInSuspendedTenant = $this->user($suspendedTenant, 'cashier', true);

        // 1. Inactive user with Sanctum token
        $inactiveSanctumToken = $inactiveUser->createToken('test')->plainTextToken;
        $res1 = $this->withHeader('Authorization', 'Bearer ' . $inactiveSanctumToken)
            ->getJson('/api/v1/outlets');
        $res1->assertStatus(401);

        // 2. User in suspended tenant
        $res2 = $this->bearer($userInSuspendedTenant)->getJson('/api/v1/outlets');
        $res2->assertStatus(403);
    }

    /**
     * P1 Finding 4: Meeting room display requires kiosk auth and excludes private fields.
     */
    public function test_room_display_requires_kiosk_auth_and_filters_private_fields(): void
    {
        $tenant = $this->tenant('room-t');
        $organizer = $this->user($tenant, 'admin');

        $roomId = DB::table('meeting_rooms')->insertGetId([
            'tenant_id' => $tenant->id,
            'name' => 'Executive Boardroom',
            'code' => 'RM-EXEC',
            'capacity' => 20,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $meetingId = DB::table('meetings')->insertGetId([
            'tenant_id' => $tenant->id,
            'room_id' => $roomId,
            'organizer_id' => $organizer->id,
            'title' => 'Quarterly Financial Review',
            'description' => 'TOP SECRET: Highly confidential strategy discussion',
            'meeting_link' => 'https://meetings.codebridges.io/rooms/secret-room-token-xyz',
            'status' => 'scheduled',
            'start_time' => now()->subMinutes(10),
            'end_time' => now()->addMinutes(50),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $rawKioskToken = 'kiosk_device_token_secret_12345';
        DB::table('meeting_room_displays')->insert([
            'tenant_id' => $tenant->id,
            'room_id' => $roomId,
            'device_token_hash' => hash('sha256', $rawKioskToken),
            'display_name' => 'Wall Display 1',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Unauthenticated request is rejected
        $unauthRes = $this->getJson("/api/v1/meetings/room-display/{$roomId}");
        $unauthRes->assertStatus(401);

        // Authenticated request with kiosk token succeeds
        $kioskRes = $this->withHeader('X-Kiosk-Token', $rawKioskToken)
            ->getJson("/api/v1/meetings/room-display/{$roomId}");
        $kioskRes->assertStatus(200);

        // Verify private fields are NOT returned
        $data = $kioskRes->json('data.current_meeting');
        $this->assertNotNull($data);
        $this->assertEquals('Quarterly Financial Review', $data['title']);
        $this->assertArrayNotHasKey('description', $data);
        $this->assertArrayNotHasKey('meeting_link', $data);
        $this->assertArrayNotHasKey('virtual_link', $data);
    }

    /**
     * P1 Finding 5: 2FA rate limiting and recovery code reauthentication.
     */
    public function test_two_factor_rate_limiting_and_recovery_code_protection(): void
    {
        $tenant = $this->tenant('2fa-t');
        $user = $this->user($tenant, 'admin');

        $twoFactorService = app(TwoFactorService::class);
        $secret = $twoFactorService->generateSecretKey(16);
        $twoFactorService->storeSecret($user, $secret);
        $twoFactorService->storeRecoveryCodes($user, ['REC-1', 'REC-2']);
        $user->forceFill([
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
        ])->save();

        // Login with 2FA enabled to acquire challenge token
        $loginRes = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'Password123!',
        ]);
        $loginRes->assertStatus(403);
        $challengeToken = $loginRes->json('challenge_token');
        $this->assertNotEmpty($challengeToken);

        // 1. Submit 5 wrong codes to trigger 429 Rate Limit
        for ($i = 0; $i < 5; $i++) {
            $res = $this->postJson('/api/v1/auth/2fa/challenge', [
                'challenge_token' => $challengeToken,
                'code' => '999999',
            ]);
            if ($i < 4) {
                $res->assertStatus(422);
            } else {
                $res->assertStatus(429);
            }
        }

        // 2. GET recovery codes without password reauth is rejected with 403
        $unauthRecovery = $this->bearer($user)->getJson('/api/v1/auth/2fa/recovery-codes');
        $unauthRecovery->assertStatus(403);

        // 3. GET recovery codes with valid password succeeds
        $authRecovery = $this->bearer($user)->getJson('/api/v1/auth/2fa/recovery-codes?password=Password123!');
        $authRecovery->assertStatus(200);
        $this->assertNotEmpty($authRecovery->json('recovery_codes'));

        \Illuminate\Support\Facades\RateLimiter::clear('2fa_attempts:' . $user->id);
        \Illuminate\Support\Facades\RateLimiter::clear('2fa_challenge_attempts:' . sha1($challengeToken));
    }

    /**
     * P1 Finding 6: GET /super-admin/tenants is strictly read-only.
     */
    public function test_super_admin_tenants_list_is_strictly_read_only(): void
    {
        $superAdminTenant = $this->tenant('platform');
        $superAdmin = $this->user($superAdminTenant, 'super_admin');

        // Tenant without an owner
        $tenantWithoutOwner = Tenant::create([
            'name' => 'Unowned Cafe',
            'slug' => 'unowned-cafe',
            'status' => 'active',
            'email' => 'unowned@sunnycafe.kh',
            'currency' => 'USD',
            'client_tier' => 'business_runner',
            'owner_user_id' => null,
        ]);

        // Unverified user with matching email
        $unverifiedUser = User::factory()->create([
            'name' => 'Stranger',
            'email' => 'unowned@sunnycafe.kh',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);

        // Super Admin calls GET /api/v1/super-admin/tenants
        $res = $this->bearer($superAdmin)->getJson('/api/v1/super-admin/tenants');
        $res->assertStatus(200);

        // Verify tenant owner_user_id is STILL null in DB (no side-effects on GET)
        $dbTenant = DB::table('tenants')->where('id', $tenantWithoutOwner->id)->first();
        $this->assertNull($dbTenant->owner_user_id);

        // Verify stranger was NOT added to tenant_users
        $membershipExists = DB::table('tenant_users')
            ->where('tenant_id', $tenantWithoutOwner->id)
            ->where('user_id', $unverifiedUser->id)
            ->exists();
        $this->assertFalse($membershipExists);
    }
}
