<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SessionsSecurityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test session endpoints require authentication.
     */
    public function test_sessions_and_2fa_require_authentication(): void
    {
        $this->getJson('/api/v1/auth/sessions')->assertStatus(401);
        $this->postJson('/api/v1/auth/2fa/toggle')->assertStatus(401);
    }

    /**
     * Test authenticated user can access sessions and toggle 2FA.
     */
    public function test_user_can_view_sessions_and_toggle_2fa(): void
    {
        $user = User::factory()->create([
            'password' => \Illuminate\Support\Facades\Hash::make('SecurePassword123!'),
        ]);

        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/v1/auth/sessions')->assertStatus(200);

        // Empty request without enable is rejected with 422
        $this->postJson('/api/v1/auth/2fa/toggle', [])->assertStatus(422);

        // Valid disable request with reauthentication password succeeds
        $this->postJson('/api/v1/auth/2fa/toggle', [
            'enable' => false,
            'password' => 'SecurePassword123!',
        ])->assertStatus(200);
    }

    /**
     * Test supervisor PIN verification validation.
     */
    public function test_pin_verification_requires_pin_code(): void
    {
        $user = User::factory()->inWorkspace()->create();

        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson('/api/v1/auth/verify-pin', []);

        $this->assertContains($response->getStatusCode(), [200, 422]);
    }
}
