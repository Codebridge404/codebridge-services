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
        \Illuminate\Support\Facades\Cache::flush();
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
     * Test registration returns complete token payload and creates user.
     */
    public function test_user_can_register_with_complete_token_payload(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'John Developer',
            'email' => 'john.dev@codebridge.dev',
            'password' => 'SecurePass123!',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'status',
                'message',
                'access_token',
                'refresh_token',
                'sanctum_token',
                'token_type',
                'expires_in',
                'user' => ['id', 'email', 'name'],
            ]);

        $this->assertNotEmpty($response->json('sanctum_token'));
        $this->assertNotEmpty($response->json('access_token'));
        $this->assertNotEmpty($response->json('refresh_token'));
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
                'access_token',
                'user' => ['id', 'email', 'name'],
            ]);
    }

    /**
     * Test user can login with phone number.
     */
    public function test_user_can_login_with_phone_number(): void
    {
        $user = User::factory()->create([
            'email' => 'phoneuser@codebridge.dev',
            'phone' => '+85512345678',
            'password' => Hash::make('Secret123!'),
            'is_active' => true,
        ]);

        // 1. Login via 'phone' field
        $response = $this->postJson('/api/v1/auth/login', [
            'phone' => '+85512345678',
            'password' => 'Secret123!',
        ]);

        $response->assertStatus(200)
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonStructure([
                'access_token',
                'user' => ['id', 'email', 'name', 'phone'],
            ]);

        // 2. Login via 'login' field with phone
        $response2 = $this->postJson('/api/v1/auth/login', [
            'login' => '+85512345678',
            'password' => 'Secret123!',
        ]);
        $response2->assertStatus(200);

        // 3. Login via 'login' field with email
        $response3 = $this->postJson('/api/v1/auth/login', [
            'login' => 'phoneuser@codebridge.dev',
            'password' => 'Secret123!',
        ]);
        $response3->assertStatus(200);

        // 4. Can login when both email and phone are provided together
        $response4 = $this->postJson('/api/v1/auth/login', [
            'email' => 'phoneuser@codebridge.dev',
            'phone' => '+85512345678',
            'password' => 'Secret123!',
        ]);
        $response4->assertStatus(200);

        // 5. Can login with phone typed into 'email' field
        $responsePhoneInEmail = $this->postJson('/api/v1/auth/login', [
            'email' => '+85512345678',
            'password' => 'Secret123!',
        ]);
        $responsePhoneInEmail->assertStatus(200);

        // 5. Reject invalid/control characters
        $response5 = $this->postJson('/api/v1/auth/login', [
            'email' => "phoneuser\0@codebridge.dev",
            'password' => 'Secret123!',
        ]);
        $response5->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    /**
     * Test MFA enabled user receives TWO_FACTOR_REQUIRED without tokens.
     */
    public function test_mfa_enabled_user_requires_two_factor(): void
    {
        $user = User::factory()->create([
            'email' => 'mfauser@codebridge.dev',
            'password' => Hash::make('Secret123!'),
            'is_active' => true,
            'two_factor_enabled' => true,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'mfauser@codebridge.dev',
            'password' => 'Secret123!',
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'status' => 'requires_mfa',
                'code' => 'TWO_FACTOR_REQUIRED',
            ])
            ->assertJsonMissing(['access_token', 'sanctum_token']);
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
                'message' => 'Too many login attempts. Please try again later.',
            ])
            ->assertJsonStructure([
                'status',
                'code',
                'message',
                'retry_after_seconds',
            ])
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->assertGreaterThan(0, $response6->json('retry_after_seconds'));
        $this->assertNotNull($response6->headers->get('Retry-After'));
    }

    /**
     * Test quickSwitch requires tenant context.
     */
    public function test_quick_switch_requires_tenant_context(): void
    {
        $response = $this->postJson('/api/v1/auth/quick-switch', [
            'pin_code' => '1234',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'status' => 'error',
                'code' => 'TENANT_REQUIRED',
            ]);
    }

    /**
     * Test quickSwitch authenticates cashier within tenant scope using PIN.
     */
    public function test_quick_switch_authenticates_cashier_within_tenant(): void
    {
        $tenant = \App\Models\Tenant::create([
            'name' => 'Demo Cafe',
            'slug' => 'demo-cafe',
            'is_active' => true,
        ]);

        $cashier = User::factory()->create([
            'tenant_id' => $tenant->id,
            'is_active' => true,
        ]);

        \App\Models\UserPosPin::create([
            'tenant_id' => $tenant->id,
            'user_id' => $cashier->id,
            'pin_hash' => Hash::make('5678'),
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/v1/auth/quick-switch', [
            'pin_code' => '5678',
            'tenant_id' => $tenant->id,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
            ]);

        $this->assertNotNull($response->json('token'));
        $this->assertEquals($cashier->id, $response->json('user.id'));
    }

    /**
     * Test logoutAllDevices invalidates existing JWT tokens.
     */
    public function test_logout_all_devices_invalidates_jwt_tokens(): void
    {
        $user = User::factory()->create([
            'email' => 'logout_jwt@codebridge.dev',
            'password' => 'Secret123!',
            'is_active' => true,
        ]);

        $loginRes = $this->postJson('/api/v1/auth/login', [
            'email' => 'logout_jwt@codebridge.dev',
            'password' => 'Secret123!',
        ]);

        $jwtToken = $loginRes->json('access_token');
        $this->assertNotNull($jwtToken);

        // Access me using JWT
        $meRes = $this->withHeader('Authorization', 'Bearer ' . $jwtToken)->getJson('/api/v1/me');
        $meRes->assertStatus(200);

        // Terminate all devices
        $logoutAllRes = $this->withHeader('Authorization', 'Bearer ' . $jwtToken)
            ->postJson('/api/v1/auth/logout-all-devices', ['include_current' => true]);
        $logoutAllRes->assertStatus(200);

        // Attempting to access me with previously issued JWT should now fail with 401 TOKEN_REVOKED
        $subsequentRes = $this->withHeader('Authorization', 'Bearer ' . $jwtToken)->getJson('/api/v1/me');
        $subsequentRes->assertStatus(401)
            ->assertJson([
                'status' => 'error',
                'code' => 'TOKEN_REVOKED',
            ]);
    }
}
