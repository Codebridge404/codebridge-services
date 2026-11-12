<?php

namespace Tests\Feature\Security;

use App\Models\Outlet;
use App\Models\Role;
use App\Models\SecurityEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Services\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class PolicySecurityEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenantA;
    protected Tenant $tenantB;
    protected User $superAdmin;
    protected User $adminA;
    protected User $staffA;
    protected User $adminB;
    protected User $staffB;
    protected Outlet $outletA;
    protected Outlet $outletB;
    protected string $tokenAdminA;
    protected string $tokenSuperAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = Tenant::create([
            'name' => 'Alpha Workspace',
            'slug' => 'alpha-workspace',
            'email' => 'admin@alpha.com',
            'client_tier' => 'pro',
            'max_users' => 10,
            'max_outlets' => 3,
        ]);

        $this->tenantB = Tenant::create([
            'name' => 'Beta Workspace',
            'slug' => 'beta-workspace',
            'email' => 'admin@beta.com',
            'client_tier' => 'pro',
            'max_users' => 10,
            'max_outlets' => 3,
        ]);

        $this->superAdmin = User::create([
            'name' => 'Global Super Admin',
            'email' => 'superadmin@codebridge.kh',
            'password' => Hash::make('Password123!'),
            'role' => 'super_admin',
            'tenant_id' => null,
            'is_active' => true,
        ]);

        $this->adminA = User::create([
            'name' => 'Admin Alpha',
            'email' => 'admin@alpha.com',
            'password' => Hash::make('Password123!'),
            'role' => 'admin',
            'tenant_id' => $this->tenantA->id,
            'is_active' => true,
        ]);

        $this->staffA = User::create([
            'name' => 'Staff Alpha',
            'email' => 'staff@alpha.com',
            'password' => Hash::make('Password123!'),
            'role' => 'employee',
            'tenant_id' => $this->tenantA->id,
            'is_active' => true,
        ]);

        $this->adminB = User::create([
            'name' => 'Admin Beta',
            'email' => 'admin@beta.com',
            'password' => Hash::make('Password123!'),
            'role' => 'admin',
            'tenant_id' => $this->tenantB->id,
            'is_active' => true,
        ]);

        $this->staffB = User::create([
            'name' => 'Staff Beta',
            'email' => 'staff@beta.com',
            'password' => Hash::make('Password123!'),
            'role' => 'employee',
            'tenant_id' => $this->tenantB->id,
            'is_active' => true,
        ]);

        $this->outletA = Outlet::create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $this->tenantA->id,
            'name' => 'Alpha Main Outlet',
            'code' => 'OUT-ALPHA',
            'is_active' => true,
        ]);

        $this->outletB = Outlet::create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $this->tenantB->id,
            'name' => 'Beta Main Outlet',
            'code' => 'OUT-BETA',
            'is_active' => true,
        ]);

        $jwt = app(JwtService::class);
        $this->tokenAdminA = $jwt->generateTokenForUser($this->adminA);
        $this->tokenSuperAdmin = $jwt->generateTokenForUser($this->superAdmin);
    }

    /**
     * Rule 1: Cross-Tenant Isolation
     * Admin of Tenant A cannot update or delete users from Tenant B.
     */
    public function test_user_policy_blocks_cross_tenant_user_modification(): void
    {
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->tokenAdminA}",
        ])->putJson("/api/v1/users/{$this->staffB->id}", [
            'name' => 'Hijacked Staff Name',
        ]);

        $response->assertStatus(403);
    }

    public function test_user_policy_blocks_cross_tenant_user_deletion(): void
    {
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->tokenAdminA}",
        ])->deleteJson("/api/v1/users/{$this->staffB->id}");

        $response->assertStatus(403);
        $this->assertDatabaseHas('users', ['id' => $this->staffB->id]);
    }

    /**
     * Rule 2: Privilege Escalation Defense
     * Tenant Admin cannot update or delete Platform Super Admin.
     */
    public function test_user_policy_blocks_modifying_platform_super_admin(): void
    {
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->tokenAdminA}",
        ])->putJson("/api/v1/users/{$this->superAdmin->id}", [
            'name' => 'Defaced Super Admin',
        ]);

        $response->assertStatus(403);
    }

    public function test_user_policy_blocks_deleting_platform_super_admin(): void
    {
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->tokenAdminA}",
        ])->deleteJson("/api/v1/users/{$this->superAdmin->id}");

        $response->assertStatus(403);
        $this->assertDatabaseHas('users', ['id' => $this->superAdmin->id]);
    }

    /**
     * Rule 3: Self-Deletion Protection
     * User cannot delete their own account via user management.
     */
    public function test_user_policy_blocks_self_deletion(): void
    {
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->tokenAdminA}",
        ])->deleteJson("/api/v1/users/{$this->adminA->id}");

        $this->assertContains($response->status(), [403, 422]);
        $this->assertDatabaseHas('users', ['id' => $this->adminA->id]);
    }

    /**
     * Rule 4: Outlet Policy Tenant Isolation
     * Admin of Tenant A cannot update or delete outlets of Tenant B.
     */
    public function test_outlet_policy_blocks_cross_tenant_outlet_tampering(): void
    {
        $updateResp = $this->withHeaders([
            'Authorization' => "Bearer {$this->tokenAdminA}",
        ])->putJson("/api/v1/outlets/{$this->outletB->id}", [
            'name' => 'Tampered Outlet Name',
        ]);

        $this->assertContains($updateResp->status(), [403, 404]);

        $deleteResp = $this->withHeaders([
            'Authorization' => "Bearer {$this->tokenAdminA}",
        ])->deleteJson("/api/v1/outlets/{$this->outletB->id}");

        $this->assertContains($deleteResp->status(), [403, 404]);
        $this->assertDatabaseHas('outlets', ['id' => $this->outletB->id]);
    }

    /**
     * Rule 5: Tenant Policy Scoped Isolation
     * Admin of Tenant A cannot access or update Tenant B settings.
     */
    public function test_tenant_policy_blocks_cross_tenant_settings_modification(): void
    {
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->tokenAdminA}",
        ])->putJson("/api/v1/tenants/{$this->tenantB->id}", [
            'name' => 'Malicious Tenant Rename',
        ]);

        $this->assertContains($response->status(), [403, 404]);
    }

    /**
     * Rule 6: Security Event Scoped Isolation
     * Tenant A cannot view security events belonging to Tenant B.
     */
    public function test_security_event_policy_enforces_tenant_isolation(): void
    {
        $eventB = SecurityEvent::create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $this->tenantB->id,
            'event_type' => 'attack_sql_injection',
            'rule_code' => 'SEC_ATTACK_SQL_INJECTION',
            'summary' => 'SQL injection on Tenant B',
            'severity' => 'high',
            'outcome' => 'blocked',
            'confidence' => 'confirmed',
            'service_name' => 'auth-service',
            'environment' => 'local',
            'ip_address' => '192.168.1.100',
            'status_code' => 403,
            'occurred_at' => now(),
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->tokenAdminA}",
        ])->getJson("/api/v1/security-events/{$eventB->id}");

        $this->assertContains($response->status(), [403, 404]);
    }

    /**
     * Rule 7: Super Admin Global Authority
     * Super Admin can manage users across all tenants without policy blocks.
     */
    public function test_super_admin_bypasses_tenant_boundaries_via_policy(): void
    {
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->tokenSuperAdmin}",
        ])->putJson("/api/v1/users/{$this->staffB->id}", [
            'name' => 'Updated By Super Admin',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('users', [
            'id' => $this->staffB->id,
            'name' => 'Updated By Super Admin',
        ]);
    }

    /**
     * Rule 8: Tenant-scoped super_admin cannot manage global IP bans or threat settings.
     */
    public function test_tenant_scoped_super_admin_cannot_manage_global_ip_bans(): void
    {
        $role = Role::create([
            'name' => 'Super Admin',
            'slug' => 'super_admin',
            'tenant_id' => null,
            'is_active' => true,
        ]);

        DB::table('user_roles')->insert([
            'user_id' => $this->adminA->id,
            'role_id' => $role->id,
            'tenant_id' => $this->tenantA->id,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $ip = '198.51.100.123';
        \App\Services\IpThreatDefenseService::unbanIp($ip);

        $this->withHeaders(['Authorization' => "Bearer {$this->tokenAdminA}"])
            ->postJson('/api/v1/security-events/ban-ip', ['ip' => $ip])
            ->assertStatus(403);

        $this->withHeaders(['Authorization' => "Bearer {$this->tokenAdminA}"])
            ->getJson('/api/v1/security-events/banned-ips')
            ->assertStatus(403);

        $this->withHeaders(['Authorization' => "Bearer {$this->tokenAdminA}"])
            ->putJson('/api/v1/security-events/settings', ['enabled' => false])
            ->assertStatus(403);

        $this->assertNull(\App\Services\IpThreatDefenseService::isBanned($ip));
    }
}
