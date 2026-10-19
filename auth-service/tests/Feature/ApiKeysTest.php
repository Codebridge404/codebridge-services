<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApiKeysTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test API keys endpoint requires authentication.
     */
    public function test_api_keys_requires_authentication(): void
    {
        $this->getJson('/api/v1/api-keys')->assertStatus(401);
    }

    /**
     * Test authenticated user can access API keys.
     */
    public function test_authenticated_user_can_view_api_keys(): void
    {
        $user = User::factory()->create();

        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/v1/api-keys')->assertStatus(200);
    }
}
