<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RolesPermissionsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test unauthenticated access to roles and permissions is unauthorized.
     */
    public function test_roles_and_permissions_require_authentication(): void
    {
        $this->getJson('/api/v1/roles')->assertStatus(401);
        $this->getJson('/api/v1/permissions')->assertStatus(401);
    }

    /**
     * Test admin can view roles list and permissions catalog.
     */
    public function test_admin_can_view_roles_and_permissions(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
        ]);

        Sanctum::actingAs($admin, ['*']);

        $this->getJson('/api/v1/roles')->assertStatus(200);
        $this->getJson('/api/v1/permissions')->assertStatus(200);
        $this->getJson('/api/v1/roles/permissions')->assertStatus(200);
    }
}
