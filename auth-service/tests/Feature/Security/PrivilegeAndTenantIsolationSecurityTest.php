<?php

namespace Tests\Feature\Security;

use App\Models\Customer;
use App\Models\Outlet;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PrivilegeAndTenantIsolationSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant1;
    protected Tenant $tenant2;
    protected User $tenant1Admin;
    protected User $tenant2Admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant1 = Tenant::create([
            'name' => 'Tenant One Corp',
            'slug' => 'tenant-one',
            'email' => 'contact@tenant1.com',
            'client_tier' => 'business_runner',
            'max_users' => 10,
            'max_outlets' => 3,
            'max_registers' => 5,
        ]);

        $this->tenant2 = Tenant::create([
            'name' => 'Tenant Two Corp',
            'slug' => 'tenant-two',
            'email' => 'contact@tenant2.com',
            'client_tier' => 'business_runner',
            'max_users' => 10,
            'max_outlets' => 3,
            'max_registers' => 5,
        ]);

        $this->tenant1Admin = User::create([
            'name' => 'Tenant 1 Admin',
            'email' => 'admin1@tenant1.com',
            'password' => Hash::make('Password123!'),
            'role' => 'admin',
            'tenant_id' => $this->tenant1->id,
            'is_active' => true,
        ]);

        $this->tenant2Admin = User::create([
            'name' => 'Tenant 2 Admin',
            'email' => 'admin2@tenant2.com',
            'password' => Hash::make('Password123!'),
            'role' => 'admin',
            'tenant_id' => $this->tenant2->id,
            'is_active' => true,
        ]);
    }

    /**
     * Test public registration strictly rejects client-side attempts to self-assign super_admin or admin roles.
     */
    public function test_public_registration_cannot_escalate_to_admin_or_super_admin(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Attacker Bob',
            'email' => 'attacker@malicious.com',
            'password' => 'HackerSecret123!',
            'role' => 'super_admin',
        ]);

        $response->assertStatus(201);
        $this->assertEquals('user', $response->json('user.role'));

        $createdUser = User::where('email', 'attacker@malicious.com')->first();
        $this->assertNotNull($createdUser);
        $this->assertEquals('user', $createdUser->role);
    }

    /**
     * Test logging out invalidates and revokes stateless JWT token immediately.
     */
    public function test_jwt_revocation_on_logout(): void
    {
        $jwtService = app(JwtService::class);
        $token = $jwtService->generateTokenForUser($this->tenant1Admin);

        // Verify valid token works
        $checkValid = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->getJson('/api/v1/me');
        $checkValid->assertStatus(200);

        // Call logout
        $logoutResponse = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->postJson('/api/v1/auth/logout');
        $logoutResponse->assertStatus(200);

        // Verify revoked token is now rejected
        $checkRevoked = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->getJson('/api/v1/me');
        $checkRevoked->assertStatus(401);
        $this->assertEquals('TOKEN_REVOKED', $checkRevoked->json('code'));
    }

    /**
     * Test deactivated user accounts cannot authenticate via existing JWT tokens.
     */
    public function test_deactivated_user_cannot_authenticate_with_jwt(): void
    {
        $jwtService = app(JwtService::class);
        $token = $jwtService->generateTokenForUser($this->tenant1Admin);

        // Deactivate the user
        $this->tenant1Admin->update(['is_active' => false]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->getJson('/api/v1/me');

        $response->assertStatus(401);
        $this->assertEquals('ACCOUNT_DEACTIVATED', $response->json('code'));
    }

    /**
     * Test Tenant 1 admin cannot modify or deactivate a user belonging to Tenant 2.
     */
    public function test_tenant_admin_cannot_modify_or_delete_other_tenant_user(): void
    {
        $tenant2User = User::create([
            'name' => 'Target Cashier',
            'email' => 'cashier@tenant2.com',
            'password' => Hash::make('Password123!'),
            'role' => 'cashier',
            'tenant_id' => $this->tenant2->id,
            'is_active' => true,
        ]);

        // Tenant 1 admin tries to update Tenant 2 user
        $updateResp = $this->actingAs($this->tenant1Admin)->putJson("/api/v1/users/{$tenant2User->id}", [
            'name' => 'Hacked Name',
        ]);
        $updateResp->assertStatus(403);

        // Tenant 1 admin tries to delete/deactivate Tenant 2 user
        $deleteResp = $this->actingAs($this->tenant1Admin)->deleteJson("/api/v1/users/{$tenant2User->id}");
        $deleteResp->assertStatus(403);

        // Verify target user was unchanged
        $freshUser = User::find($tenant2User->id);
        $this->assertEquals('Target Cashier', $freshUser->name);
        $this->assertTrue($freshUser->is_active);
    }

    /**
     * Test non-super_admin cannot escalate a user to super_admin.
     */
    public function test_non_super_admin_cannot_escalate_user_to_super_admin(): void
    {
        $tenant1User = User::create([
            'name' => 'Staff Alice',
            'email' => 'alice@tenant1.com',
            'password' => Hash::make('Password123!'),
            'role' => 'employee',
            'tenant_id' => $this->tenant1->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->tenant1Admin)->putJson("/api/v1/users/{$tenant1User->id}", [
            'role' => 'super_admin',
        ]);

        $response->assertStatus(200);

        $fresh = User::find($tenant1User->id);
        $this->assertNotEquals('super_admin', $fresh->role);
    }

    /**
     * Test core system default roles cannot be modified or deleted.
     */
    public function test_system_role_cannot_be_modified_or_deleted(): void
    {
        $systemRole = Role::firstOrCreate(
            ['slug' => 'super_admin'],
            ['name' => 'Super Administrator', 'is_system' => true]
        );

        // Attempt update
        $updateResp = $this->actingAs($this->tenant1Admin)->putJson("/api/v1/roles/{$systemRole->id}", [
            'name' => 'Renamed Super Admin',
        ]);
        $updateResp->assertStatus(422);

        // Attempt delete
        $deleteResp = $this->actingAs($this->tenant1Admin)->deleteJson("/api/v1/roles/{$systemRole->id}");
        $deleteResp->assertStatus(422);
    }

    /**
     * Test Tenant 1 admin cannot modify or delete custom roles belonging to Tenant 2.
     */
    public function test_tenant_cannot_modify_other_tenant_role(): void
    {
        $tenant2Role = Role::create([
            'name' => 'Tenant 2 Barista',
            'slug' => 'tenant_2_barista',
            'company_id' => $this->tenant2->id,
            'is_system' => false,
        ]);

        $updateResp = $this->actingAs($this->tenant1Admin)->putJson("/api/v1/roles/{$tenant2Role->id}", [
            'name' => 'Compromised Role',
        ]);
        $updateResp->assertStatus(403);

        $deleteResp = $this->actingAs($this->tenant1Admin)->deleteJson("/api/v1/roles/{$tenant2Role->id}");
        $deleteResp->assertStatus(403);
    }

    /**
     * Test Tenant 1 admin cannot modify or delete store outlets belonging to Tenant 2.
     */
    public function test_tenant_cannot_modify_other_tenant_outlet(): void
    {
        $tenant2Outlet = Outlet::create([
            'name' => 'Tenant 2 Downtown',
            'code' => 'T2-DT',
            'tenant_id' => $this->tenant2->id,
            'business_type' => 'retail_mart',
            'is_active' => true,
        ]);

        $updateResp = $this->actingAs($this->tenant1Admin)->putJson("/api/v1/outlets/{$tenant2Outlet->id}", [
            'name' => 'Hijacked Outlet',
        ]);
        $updateResp->assertStatus(403);

        $deleteResp = $this->actingAs($this->tenant1Admin)->deleteJson("/api/v1/outlets/{$tenant2Outlet->id}");
        $deleteResp->assertStatus(403);
    }

    /**
     * Test customer tenant isolation prevents client-side header or query spoofing.
     */
    public function test_customer_tenant_isolation_prevents_header_spoofing(): void
    {
        Customer::create([
            'name' => 'Tenant 1 Customer',
            'email' => 'cust1@tenant1.com',
            'tenant_id' => $this->tenant1->id,
        ]);

        $cust2 = Customer::create([
            'name' => 'Tenant 2 Customer',
            'email' => 'cust2@tenant2.com',
            'tenant_id' => $this->tenant2->id,
        ]);

        // Tenant 1 admin requests customers with spoofed X-Tenant-Id: 2
        $response = $this->actingAs($this->tenant1Admin)
            ->withHeader('X-Tenant-Id', (string) $this->tenant2->id)
            ->getJson('/api/v1/customers');

        $response->assertStatus(200);
        $data = $response->json('data');
        $emails = collect($data)->pluck('email')->toArray();

        // Must ONLY contain Tenant 1 customer, never Tenant 2
        $this->assertContains('cust1@tenant1.com', $emails);
        $this->assertNotContains('cust2@tenant2.com', $emails);

        // Tenant 1 admin attempts to update Tenant 2 customer
        $updateResp = $this->actingAs($this->tenant1Admin)->putJson("/api/v1/customers/{$cust2->id}", [
            'name' => 'Updated By Tenant 1',
        ]);
        $updateResp->assertStatus(404);
    }

    /**
     * Test supervisor PIN verification is strictly scoped to the tenant organization.
     */
    public function test_supervisor_pin_verification_strictly_scoped_to_tenant(): void
    {
        // Supervisor in Tenant 2 with PIN 8888
        $supervisorTenant2 = User::create([
            'name' => 'Supervisor Bob',
            'email' => 'sup2@tenant2.com',
            'password' => Hash::make('Password123!'),
            'role' => 'supervisor',
            'pin_code' => '8888',
            'tenant_id' => $this->tenant2->id,
            'is_active' => true,
        ]);

        // Cashier in Tenant 1 tries to use Tenant 2 supervisor's PIN 8888
        $cashierTenant1 = User::create([
            'name' => 'Cashier Charlie',
            'email' => 'cashier1@tenant1.com',
            'password' => Hash::make('Password123!'),
            'role' => 'cashier',
            'pin_code' => '1111',
            'tenant_id' => $this->tenant1->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($cashierTenant1)->postJson('/api/v1/auth/verify-pin', [
            'pin_code' => '8888',
        ]);

        // Must reject authorization across tenant boundary
        $response->assertStatus(403);
    }
}
