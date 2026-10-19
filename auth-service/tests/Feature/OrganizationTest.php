<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrganizationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test organization endpoints require authentication.
     */
    public function test_organization_endpoints_require_authentication(): void
    {
        $this->getJson('/api/v1/outlets')->assertStatus(401);
        $this->getJson('/api/v1/departments')->assertStatus(401);
        $this->getJson('/api/v1/suppliers')->assertStatus(401);
        $this->getJson('/api/v1/customers')->assertStatus(401);
    }

    /**
     * Test authenticated staff can view organization entities.
     */
    public function test_authenticated_user_can_view_organization_entities(): void
    {
        $user = User::factory()->create([
            'role' => 'admin',
        ]);

        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/v1/outlets')->assertStatus(200);
        $this->getJson('/api/v1/departments')->assertStatus(200);
        $this->getJson('/api/v1/suppliers')->assertStatus(200);
        $this->getJson('/api/v1/customers')->assertStatus(200);
        $this->getJson('/api/v1/employees')->assertStatus(200);
    }
}
