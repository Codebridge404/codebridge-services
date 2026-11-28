<?php

namespace Tests\Feature;

use App\Models\Auth\User;
use App\Models\Organization\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SuperAdminTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test regular user cannot access super-admin endpoints.
     */
    public function test_regular_user_cannot_access_super_admin(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
        ]);

        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/v1/super-admin/tenants')->assertStatus(403);
    }

    /**
     * Test super_admin can view tenants and telemetry.
     */
    public function test_super_admin_can_access_dashboard_and_tenants(): void
    {
        $superAdmin = User::factory()->create([
            'role' => 'super_admin',
        ]);

        Sanctum::actingAs($superAdmin, ['*']);

        $this->getJson('/api/v1/super-admin/tenants')->assertStatus(200);
        $this->getJson('/api/v1/super-admin/subscription-plans')->assertStatus(200);
        $this->getJson('/api/v1/super-admin/infrastructure/telemetry')->assertStatus(200);
    }

    /**
     * Test super_admin can list tenants when tenants have NULL owner_user_id (regression test).
     */
    public function test_super_admin_can_list_tenants_with_null_owner_and_unassigned_relations(): void
    {
        $superAdmin = User::factory()->create([
            'role' => 'super_admin',
        ]);

        // Insert tenant with NULL owner_user_id (simulates newly created or unlinked tenant)
        DB::table('tenants')->insert([
            'name' => 'Standalone Tenant Without Owner',
            'slug' => 'standalone-no-owner',
            'client_tier' => 'business_runner',
            'status' => 'active',
            'owner_user_id' => null,
            'email' => 'unlinked@standalone.dev',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($superAdmin, ['*']);

        $response = $this->getJson('/api/v1/super-admin/tenants');
        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'slug',
                        'client_tier',
                        'status',
                        'enabled_modules',
                        'payment_settings',
                        'outlets_count',
                        'users_count',
                        'owner',
                        'subscription',
                        'subscriptions',
                        'created_at',
                        'updated_at',
                    ],
                ],
                'stats' => [
                    'total',
                    'active',
                    'trial',
                    'suspended',
                    'enterprise_org',
                    'business_runner',
                    'free_personal',
                ],
            ]);

        $this->assertTrue($response->json('success'));
        $this->assertCount(1, $response->json('data'));
        $this->assertNull($response->json('data.0.owner'));
        $this->assertIsArray($response->json('data.0.subscriptions'));
        $this->assertEquals(1, $response->json('stats.total'));
    }

    public function test_super_admin_can_crud_tenant_by_uuid_and_id(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin']);
        $tenant = Tenant::create([
            'name' => 'UUID Org',
            'slug' => 'uuid-org-crud',
            'uuid' => '550e8400-e29b-41d4-a716-446655440099',
            'status' => 'active',
        ]);

        Sanctum::actingAs($superAdmin, ['*']);

        // Show by UUID
        $this->getJson("/api/v1/super-admin/tenants/{$tenant->uuid}")
            ->assertOk()
            ->assertJsonPath('data.name', 'UUID Org');

        // Update by UUID (PUT)
        $this->putJson("/api/v1/super-admin/tenants/{$tenant->uuid}", [
            'name' => 'UUID Org Updated',
        ])->assertOk()
            ->assertJsonPath('data.name', 'UUID Org Updated');

        // Update by UUID (PATCH)
        $this->patchJson("/api/v1/super-admin/tenants/{$tenant->uuid}", [
            'name' => 'UUID Org Patched',
        ])->assertOk()
            ->assertJsonPath('data.name', 'UUID Org Patched');

        // Suspend by UUID
        $this->postJson("/api/v1/super-admin/tenants/{$tenant->uuid}/suspend")
            ->assertOk()
            ->assertJsonPath('status', 'suspended');

        // Delete by UUID
        $this->deleteJson("/api/v1/super-admin/tenants/{$tenant->uuid}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('tenants', ['id' => $tenant->id]);
    }
}
