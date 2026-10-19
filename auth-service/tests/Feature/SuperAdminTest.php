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
}
