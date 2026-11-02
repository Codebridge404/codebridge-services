<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test unauthenticated access to admin endpoints is rejected.
     */
    public function test_unauthenticated_admin_access_is_blocked(): void
    {
        $this->getJson('/api/v1/admin/dashboard')->assertStatus(401);
        $this->getJson('/api/v1/admin/users')->assertStatus(401);
        $this->getJson('/api/v1/admin/roles')->assertStatus(401);
        $this->getJson('/api/v1/admin/tenants')->assertStatus(401);
    }

    /**
     * Test regular user cannot access admin endpoints.
     */
    public function test_regular_user_cannot_access_admin_endpoints(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
        ]);

        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/v1/admin/dashboard')->assertStatus(403);
        $this->getJson('/api/v1/admin/users')->assertStatus(403);
        $this->getJson('/api/v1/admin/roles')->assertStatus(403);
    }

    /**
     * Test super_admin can view platform-wide dashboard, tenants, users, and all roles.
     */
    public function test_super_admin_can_access_platform_dashboard_and_directory(): void
    {
        $superAdmin = User::factory()->create([
            'role' => 'super_admin',
        ]);

        $tenantA = DB::table('tenants')->insertGetId([
            'name' => 'Tenant Alpha',
            'slug' => 'tenant-alpha',
            'client_tier' => 'enterprise_org',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $tenantB = DB::table('tenants')->insertGetId([
            'name' => 'Tenant Beta',
            'slug' => 'tenant-beta',
            'client_tier' => 'business_runner',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($superAdmin, ['*']);

        // Dashboard
        $response = $this->getJson('/api/v1/admin/dashboard');
        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'role' => 'super_admin',
                'scope' => 'platform',
            ])
            ->assertJsonStructure([
                'data' => [
                    'overview' => ['total_tenants', 'total_users', 'total_roles'],
                    'tier_breakdown',
                ],
            ]);

        // Users
        $this->getJson('/api/v1/admin/users')
            ->assertStatus(200)
            ->assertJson(['status' => 'success', 'caller_role' => 'super_admin']);

        // Roles
        $this->getJson('/api/v1/admin/roles')
            ->assertStatus(200)
            ->assertJson(['status' => 'success', 'caller_role' => 'super_admin']);

        // Tenants
        $this->getJson('/api/v1/admin/tenants')
            ->assertStatus(200)
            ->assertJson(['status' => 'success']);
    }

    /**
     * Test organization admin can only view tenant-scoped dashboard and users.
     */
    public function test_organization_admin_dashboard_is_tenant_scoped(): void
    {
        $tenant1 = DB::table('tenants')->insertGetId([
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'client_tier' => 'business_runner',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $tenant2 = DB::table('tenants')->insertGetId([
            'name' => 'Tenant Two',
            'slug' => 'tenant-two',
            'client_tier' => 'enterprise_org',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $orgAdmin = User::factory()->create([
            'role' => 'organization',
            'tenant_id' => $tenant1,
        ]);

        $userTenant2 = User::factory()->create([
            'email' => 'other_tenant_user@codebridge.dev',
            'role' => 'employee',
            'tenant_id' => $tenant2,
        ]);

        Sanctum::actingAs($orgAdmin, ['*']);

        // Dashboard
        $dashboardResponse = $this->getJson('/api/v1/admin/dashboard');
        $dashboardResponse->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'role' => 'organization',
                'scope' => 'tenant',
                'data' => [
                    'tenant' => ['name' => 'Tenant One'],
                ],
            ]);

        // Users list should ONLY contain tenant1 users, NOT tenant2 users
        $usersResponse = $this->getJson('/api/v1/admin/users');
        $usersResponse->assertStatus(200);
        $userEmails = collect($usersResponse->json('data'))->pluck('email')->toArray();

        $this->assertContains($orgAdmin->email, $userEmails);
        $this->assertNotContains('other_tenant_user@codebridge.dev', $userEmails);
    }
}

