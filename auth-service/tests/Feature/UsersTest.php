<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UsersTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test unauthenticated access to users is blocked.
     */
    public function test_unauthenticated_user_access_is_blocked(): void
    {
        $this->getJson('/api/v1/users')->assertStatus(401);
        $this->getJson('/api/v1/users/lookup')->assertStatus(401);
    }

    /**
     * Test admin can access users directory and lookup.
     */
    public function test_admin_can_access_users_list_and_lookup(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
        ]);

        Sanctum::actingAs($admin, ['*']);

        $this->getJson('/api/v1/users')->assertStatus(200);
        $this->getJson('/api/v1/users/lookup')->assertStatus(200);
    }
}
