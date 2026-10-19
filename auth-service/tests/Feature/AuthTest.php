<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('auth_attempt:' . sha1('127.0.0.1|victim@codebridge.dev'));
        RateLimiter::clear('auth_attempt:' . sha1('127.0.0.1|admin@codebridge.dev'));
        RateLimiter::clear('auth_attempt:' . sha1('127.0.0.1|guest'));
    }

    /**
     * Test microservice health check endpoint.
     */
    public function test_auth_service_health_check_returns_healthy(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'healthy',
                'service' => 'auth-service',
            ]);
    }

    /**
     * Test login validation requires email and password.
     */
    public function test_login_requires_email_and_password(): void
    {
        $response = $this->postJson('/api/v1/auth/login', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'password']);
    }

    /**
     * Test successful login returns Sanctum token.
     */
    public function test_user_can_login_with_valid_credentials(): void
    {
        $user = User::factory()->create([
            'email' => 'admin@codebridge.dev',
            'password' => Hash::make('Secret123!'),
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@codebridge.dev',
            'password' => 'Secret123!',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'token',
                'user' => ['id', 'email', 'name'],
            ]);
    }

    /**
     * Test authenticated user can access /me profile and logout.
     */
    public function test_authenticated_user_can_access_me_and_logout(): void
    {
        $user = User::factory()->create([
            'email' => 'member@codebridge.dev',
        ]);

        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/v1/me')->assertStatus(200);
        $this->postJson('/api/v1/auth/logout')->assertStatus(200);
    }

    /**
     * Test account lockout after consecutive failed attempts (Mass ATO / Brute-force protection).
     */
    public function test_account_locks_out_after_five_failed_attempts(): void
    {
        $user = User::factory()->create([
            'email' => 'victim@codebridge.dev',
            'password' => Hash::make('CorrectPassword!'),
            'is_active' => true,
            'failed_login_attempts' => 0,
        ]);

        // Attempts 1 to 5: Should fail with 422
        for ($i = 1; $i <= 5; $i++) {
            $response = $this->postJson('/api/v1/auth/login', [
                'email' => 'victim@codebridge.dev',
                'password' => 'WrongPassword',
            ]);
            $response->assertStatus(422);
        }

        // Attempt 6: Security Cooldown Active (429 ACCOUNT_LOCKED_OUT)
        $response6 = $this->postJson('/api/v1/auth/login', [
            'email' => 'victim@codebridge.dev',
            'password' => 'CorrectPassword!',
        ]);

        $response6->assertStatus(429)
            ->assertJson([
                'status' => 'error',
                'code' => 'ACCOUNT_LOCKED_OUT',
            ]);
    }
}
