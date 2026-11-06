<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        \Illuminate\Support\Facades\DB::table('tenants')->insert([
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
}
