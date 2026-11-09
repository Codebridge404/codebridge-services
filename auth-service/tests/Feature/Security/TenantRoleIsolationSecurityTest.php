<?php

namespace Tests\Feature\Security;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class TenantRoleIsolationSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function createTenant(string $name, string $status = 'active'): Tenant
    {
        return Tenant::create([
            'name' => $name,
            'slug' => Str::slug($name) . '-' . Str::random(4),
            'status' => $status,
            'email' => Str::slug($name) . '@example.com',
            'currency' => 'USD',
            'client_tier' => 'enterprise_org',
            'max_users' => 100,
            'max_outlets' => 20,
            'max_registers' => 50,
        ]);
    }

    private function ensureRole(string $slug, ?string $parentRole = null): Role
    {
        return Role::firstOrCreate(
            ['slug' => $slug, 'tenant_id' => null],
            [
                'uuid' => (string) Str::uuid(),
                'name' => ucfirst(str_replace('_', ' ', $slug)),
                'parent_role' => $parentRole ?? ($slug === 'super_admin' ? 'super_admin' : ($slug === 'admin' ? 'organization' : 'employee')),
                'is_system' => true,
                'is_active' => true,
                'tenant_id' => null,
                'company_id' => null,
            ]
        );
    }

    private function createMultiTenantUser(
        Tenant $tenantA,
        string $roleInA,
        Tenant $tenantB,
        string $roleInB,
        bool $insertAFirst = true
    ): User {
        $roleA = $this->ensureRole($roleInA);
        $roleB = $this->ensureRole($roleInB);

        $user = User::create([
            'name' => 'Multi Tenant User',
            'email' => 'multi.' . Str::random(6) . '@example.com',
            'password' => Hash::make('Password123!'),
            'is_active' => true,
        ]);

        $assignments = $insertAFirst
            ? [[$tenantA, $roleA], [$tenantB, $roleB]]
            : [[$tenantB, $roleB], [$tenantA, $roleA]];

        foreach ($assignments as [$tenant, $role]) {
            DB::table('tenant_users')->insert([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'status' => 'active',
                'joined_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('user_roles')->insert([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'role_id' => $role->id,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $user;
    }

    private function issueJwtForTenant(User $user, ?int $tenantId, array $extraClaims = []): string
    {
        Auth::forgetGuards();
        $this->app->forgetInstance('auth.driver');

        $scopedUser = clone $user;
        $scopedUser->pendingTenantId = $tenantId;
        $scopedUser->unsetRelation('roles');
        $scopedUser->unsetRelation('tenantUsers');

        return app(JwtService::class)->generateTokenForUser($scopedUser, array_merge([
            'tenant_id' => $tenantId,
        ], $extraClaims));
    }

    public function test_admin_in_tenant_a_and_cashier_in_tenant_b_cannot_escalate_in_tenant_b_regardless_of_insertion_order(): void
    {
        foreach ([true, false] as $insertAFirst) {
            $tenantA = $this->createTenant("Tenant Alpha " . ($insertAFirst ? '1' : '2'));
            $tenantB = $this->createTenant("Tenant Beta " . ($insertAFirst ? '1' : '2'));

            $user = $this->createMultiTenantUser($tenantA, 'admin', $tenantB, 'cashier', $insertAFirst);

            $jwtTenantA = $this->issueJwtForTenant($user, $tenantA->id);
            $jwtTenantB = $this->issueJwtForTenant($user, $tenantB->id);

            // 1. Auth-owned authorization contract reflects exact scoped roles
            $ctxB = $this->withHeader('Authorization', 'Bearer ' . $jwtTenantB)
                ->getJson('/api/v1/auth/authorize-context');
            $ctxB->assertStatus(200)
                ->assertJsonPath('authorized', true)
                ->assertJsonPath('data.tenant_id', $tenantB->id)
                ->assertJsonPath('data.role', 'cashier')
                ->assertJsonPath('data.roles', ['cashier'])
                ->assertJsonPath('data.is_super_admin', false);
            $this->assertNotContains('*', $ctxB->json('data.permissions') ?? []);

            $ctxA = $this->withHeader('Authorization', 'Bearer ' . $jwtTenantA)
                ->getJson('/api/v1/auth/authorize-context');
            $ctxA->assertStatus(200)
                ->assertJsonPath('authorized', true)
                ->assertJsonPath('data.tenant_id', $tenantA->id)
                ->assertJsonPath('data.role', 'admin')
                ->assertJsonPath('data.roles', ['admin'])
                ->assertJsonPath('data.permissions', ['*']);

            // 2. Administrative mutations in Tenant B must be rejected with 403 and cause zero DB changes
            $beforeUsers = DB::table('users')->count();
            $beforeOutlets = DB::table('outlets')->count();

            $this->withHeader('Authorization', 'Bearer ' . $jwtTenantB)
                ->postJson('/api/v1/users', [
                    'name' => 'Unauthorized Staff',
                    'email' => 'unauth.staff.' . Str::random(5) . '@example.com',
                    'password' => 'SecretPass123!',
                    'role' => 'cashier',
                ])->assertStatus(403);

            $this->withHeader('Authorization', 'Bearer ' . $jwtTenantB)
                ->postJson('/api/v1/outlets', [
                    'name' => 'Unauthorized Outlet',
                    'code' => 'UNAUTH-' . Str::upper(Str::random(4)),
                    'business_type' => 'cafe_qsr',
                ])->assertStatus(403);

            $this->withHeader('Authorization', 'Bearer ' . $jwtTenantB)
                ->putJson('/api/v1/tenants/profile', [
                    'name' => 'Hijacked Tenant Beta',
                ])->assertStatus(403);

            $this->assertSame($beforeUsers, DB::table('users')->count());
            $this->assertSame($beforeOutlets, DB::table('outlets')->count());
            $this->assertNotSame('Hijacked Tenant Beta', DB::table('tenants')->where('id', $tenantB->id)->value('name'));

            // 3. Same user in Tenant A succeeds on administrative outlet mutation scoped strictly to Tenant A
            $createOutletA = $this->withHeader('Authorization', 'Bearer ' . $jwtTenantA)
                ->postJson('/api/v1/outlets', [
                    'name' => 'Authorized Alpha Branch',
                    'code' => 'ALPHA-' . Str::upper(Str::random(4)),
                    'business_type' => 'cafe_qsr',
                ]);
            $createOutletA->assertStatus(201);
            $this->assertDatabaseHas('outlets', [
                'tenant_id' => $tenantA->id,
                'name' => 'Authorized Alpha Branch',
            ]);
        }
    }

    public function test_spoofing_requested_tenant_header_or_query_on_authorize_context_is_rejected_and_logged(): void
    {
        $tenantA = $this->createTenant('Spoof Alpha');
        $tenantB = $this->createTenant('Spoof Beta');

        $user = $this->createMultiTenantUser($tenantA, 'admin', $tenantB, 'cashier', true);
        $jwtTenantB = $this->issueJwtForTenant($user, $tenantB->id);

        $beforeEvents = DB::table('security_events')->count();

        $this->withHeaders([
            'Authorization' => 'Bearer ' . $jwtTenantB,
            'X-Tenant-Id' => (string) $tenantA->id,
        ])->getJson('/api/v1/auth/authorize-context')
            ->assertStatus(403)
            ->assertJsonPath('authorized', false)
            ->assertJsonPath('code', 'TENANT_CONTEXT_MISMATCH');

        $this->withHeader('Authorization', 'Bearer ' . $jwtTenantB)
            ->getJson('/api/v1/auth/authorize-context?tenant_id=' . $tenantA->id)
            ->assertStatus(403)
            ->assertJsonPath('authorized', false)
            ->assertJsonPath('code', 'TENANT_CONTEXT_MISMATCH');

        $this->withHeaders([
            'Authorization' => 'Bearer ' . $jwtTenantB,
            'X-Tenant-Id' => 'all',
        ])->getJson('/api/v1/auth/authorize-context')
            ->assertStatus(403)
            ->assertJsonPath('authorized', false);

        $this->withHeaders([
            'Authorization' => 'Bearer ' . $jwtTenantB,
            'X-Tenant-Id' => 'not-an-id',
        ])->getJson('/api/v1/auth/authorize-context')
            ->assertStatus(403)
            ->assertJsonPath('code', 'INVALID_TENANT_CONTEXT');

        $this->assertGreaterThan($beforeEvents, DB::table('security_events')->count());
        $this->assertDatabaseHas('security_events', [
            'user_id' => $user->id,
            'rule_code' => 'SEC_TENANT_ACCESS_DENIED',
            'outcome' => 'blocked',
        ]);
    }

    public function test_suspended_membership_or_suspended_tenant_blocks_selected_tenant_even_if_active_elsewhere(): void
    {
        $activeTenant = $this->createTenant('Active Coffee');
        $suspendedTenant = $this->createTenant('Suspended Coffee', 'suspended');
        $membershipSuspendedTenant = $this->createTenant('Membership Suspended Coffee', 'active');

        $adminRole = $this->ensureRole('admin');
        $user = User::create([
            'name' => 'Selective Access User',
            'email' => 'selective@example.com',
            'password' => Hash::make('Password123!'),
            'is_active' => true,
        ]);

        foreach (
            [
                [$activeTenant->id, 'active'],
                [$suspendedTenant->id, 'active'],
                [$membershipSuspendedTenant->id, 'suspended'],
            ] as [$tid, $status]
        ) {
            DB::table('tenant_users')->insert([
                'tenant_id' => $tid,
                'user_id' => $user->id,
                'status' => $status,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('user_roles')->insert([
                'tenant_id' => $tid,
                'user_id' => $user->id,
                'role_id' => $adminRole->id,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Suspended tenant status rejects both /authorize-context and protected workspace routes
        $jwtSuspendedTenant = $this->issueJwtForTenant($user, $suspendedTenant->id);
        $this->withHeader('Authorization', 'Bearer ' . $jwtSuspendedTenant)
            ->getJson('/api/v1/auth/authorize-context')
            ->assertStatus(403)
            ->assertJsonPath('code', 'TENANT_INACTIVE');
        $this->withHeader('Authorization', 'Bearer ' . $jwtSuspendedTenant)
            ->getJson('/api/v1/outlets')
            ->assertStatus(403);

        // Suspended membership in active tenant rejects both /authorize-context and workspace routes
        $jwtSuspendedMembership = $this->issueJwtForTenant($user, $membershipSuspendedTenant->id);
        $this->withHeader('Authorization', 'Bearer ' . $jwtSuspendedMembership)
            ->getJson('/api/v1/auth/authorize-context')
            ->assertStatus(403)
            ->assertJsonPath('code', 'MEMBERSHIP_REQUIRED');
        $this->withHeader('Authorization', 'Bearer ' . $jwtSuspendedMembership)
            ->getJson('/api/v1/outlets')
            ->assertStatus(403);

        // Active tenant continues to succeed
        $jwtActive = $this->issueJwtForTenant($user, $activeTenant->id);
        $this->withHeader('Authorization', 'Bearer ' . $jwtActive)
            ->getJson('/api/v1/auth/authorize-context')
            ->assertStatus(200)
            ->assertJsonPath('authorized', true)
            ->assertJsonPath('data.tenant_id', $activeTenant->id);
    }

    public function test_platform_super_admin_is_explicit_and_tenant_scoped_super_admin_row_does_not_leak(): void
    {
        $tenantA = $this->createTenant('Platform Test A');
        $tenantB = $this->createTenant('Platform Test B');

        $platformSuperAdmin = User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $platformJwt = $this->issueJwtForTenant($platformSuperAdmin, null);
        $this->withHeaders([
            'Authorization' => 'Bearer ' . $platformJwt,
            'X-Tenant-Id' => (string) $tenantB->id,
        ])->getJson('/api/v1/auth/authorize-context')
            ->assertStatus(200)
            ->assertJsonPath('data.is_super_admin', true)
            ->assertJsonPath('data.is_platform_super_admin', true)
            ->assertJsonPath('data.tenant_id', $tenantB->id);

        $this->withHeaders([
            'Authorization' => 'Bearer ' . $platformJwt,
            'X-Tenant-Id' => 'all',
        ])->getJson('/api/v1/auth/authorize-context')
            ->assertStatus(200)
            ->assertJsonPath('data.is_platform_super_admin', true)
            ->assertJsonPath('data.requested_tenant_scope', 'all');

        // Deactivated platform super admin must still be rejected with 401
        $platformSuperAdmin->update(['is_active' => false]);
        $this->withHeader('Authorization', 'Bearer ' . $platformJwt)
            ->getJson('/api/v1/auth/authorize-context')
            ->assertStatus(401)
            ->assertJsonPath('code', 'ACCOUNT_DEACTIVATED');

        // User with super_admin pivot row bound strictly to Tenant A and cashier in Tenant B
        $superRole = $this->ensureRole('super_admin');
        $cashierRole = $this->ensureRole('cashier');

        $scopedUser = User::create([
            'name' => 'Scoped Super User',
            'email' => 'scoped.super@example.com',
            'password' => Hash::make('Password123!'),
            'is_active' => true,
        ]);

        foreach ([[$tenantA, $superRole], [$tenantB, $cashierRole]] as [$tenant, $role]) {
            DB::table('tenant_users')->insert([
                'tenant_id' => $tenant->id,
                'user_id' => $scopedUser->id,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('user_roles')->insert([
                'tenant_id' => $tenant->id,
                'user_id' => $scopedUser->id,
                'role_id' => $role->id,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $jwtInB = $this->issueJwtForTenant($scopedUser, $tenantB->id);
        $this->withoutHeader('X-Tenant-Id')
            ->withHeader('Authorization', 'Bearer ' . $jwtInB)
            ->getJson('/api/v1/auth/authorize-context')
            ->assertStatus(200)
            ->assertJsonPath('data.is_super_admin', false)
            ->assertJsonPath('data.is_platform_super_admin', false)
            ->assertJsonPath('data.roles', ['cashier']);

        $this->withHeaders([
            'Authorization' => 'Bearer ' . $jwtInB,
            'X-Tenant-Id' => 'all',
        ])->getJson('/api/v1/auth/authorize-context')
            ->assertStatus(403);

        $this->withoutHeader('X-Tenant-Id')
            ->withHeader('Authorization', 'Bearer ' . $jwtInB)
            ->getJson('/api/v1/super-admin/tenants')
            ->assertStatus(403);
    }

    public function test_pos_session_jwt_can_inspect_authorize_context_but_cannot_mutate_outlets(): void
    {
        $tenant = $this->createTenant('POS Context Cafe');
        $user = User::factory()->inWorkspace()->create([
            'tenant_id' => $tenant->id,
            'role' => 'admin',
            'is_active' => true,
        ]);

        $posJwt = $this->issueJwtForTenant($user, $tenant->id, [
            'is_pos_session' => true,
            'scope' => 'pos',
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $posJwt)
            ->getJson('/api/v1/auth/authorize-context')
            ->assertStatus(200)
            ->assertJsonPath('data.is_pos_session', true)
            ->assertJsonPath('data.tenant_id', $tenant->id);

        $this->withHeader('Authorization', 'Bearer ' . $posJwt)
            ->postJson('/api/v1/outlets', [
                'name' => 'POS Attempted Outlet',
                'code' => 'POS-ATT-' . Str::upper(Str::random(4)),
                'business_type' => 'cafe_qsr',
            ])
            ->assertStatus(403);
    }

    public function test_user_update_and_destroy_and_pin_verification_are_scoped_to_selected_tenant(): void
    {
        $tenantA = $this->createTenant('Isolation Update A');
        $tenantB = $this->createTenant('Isolation Update B');

        $adminB = User::factory()->inWorkspace()->create([
            'tenant_id' => $tenantB->id,
            'role' => 'admin',
            'is_active' => true,
        ]);

        // Target is cashier in Tenant A (inserted first) and cashier in Tenant B
        $multiTarget = $this->createMultiTenantUser($tenantA, 'cashier', $tenantB, 'cashier', true);
        $cashierRole = $this->ensureRole('cashier');
        $supervisorRole = $this->ensureRole('supervisor');
        $clerkRole = $this->ensureRole('inventory_clerk');

        // Give multiTarget a PIN in Tenant A; in Tenant B make multiTarget supervisor (inserted AFTER Tenant A)
        DB::table('user_pos_pins')->insert([
            'tenant_id' => $tenantA->id,
            'user_id' => $multiTarget->id,
            'pin_hash' => hash('sha256', '7788'),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // In Tenant A verifyPin with cashier's PIN 7788 MUST fail (even if promoted to supervisor in Tenant B!)
        DB::table('user_roles')
            ->where('tenant_id', $tenantB->id)
            ->where('user_id', $multiTarget->id)
            ->update(['role_id' => $supervisorRole->id]);

        $cashierInA = User::factory()->inWorkspace()->create([
            'tenant_id' => $tenantA->id,
            'role' => 'cashier',
            'is_active' => true,
        ]);
        $jwtCashierA = $this->issueJwtForTenant($cashierInA, $tenantA->id);

        $this->withHeader('Authorization', 'Bearer ' . $jwtCashierA)
            ->postJson('/api/v1/auth/verify-pin', ['pin_code' => '7788'])
            ->assertStatus(403);

        // Tenant B admin updating multiTarget's role must update ONLY Tenant B and keep Tenant A's cashier role intact
        $jwtAdminB = $this->issueJwtForTenant($adminB, $tenantB->id);
        $this->withHeader('Authorization', 'Bearer ' . $jwtAdminB)
            ->putJson("/api/v1/users/{$multiTarget->id}", [
                'role' => 'inventory_clerk',
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('user_roles', [
            'tenant_id' => $tenantB->id,
            'user_id' => $multiTarget->id,
            'role_id' => $clerkRole->id,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('user_roles', [
            'tenant_id' => $tenantA->id,
            'user_id' => $multiTarget->id,
            'role_id' => $cashierRole->id,
            'is_active' => true,
        ]);

        // Tenant B admin removing multiTarget removes ONLY Tenant B membership while preserving Tenant A user & role
        $this->withHeader('Authorization', 'Bearer ' . $jwtAdminB)
            ->deleteJson("/api/v1/users/{$multiTarget->id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('tenant_users', [
            'tenant_id' => $tenantB->id,
            'user_id' => $multiTarget->id,
        ]);
        $this->assertDatabaseHas('tenant_users', [
            'tenant_id' => $tenantA->id,
            'user_id' => $multiTarget->id,
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('user_roles', [
            'tenant_id' => $tenantA->id,
            'user_id' => $multiTarget->id,
            'role_id' => $cashierRole->id,
            'is_active' => true,
        ]);
    }
}
