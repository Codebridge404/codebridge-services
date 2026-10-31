<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationsAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        $this->secret = config('jwt.secret', 'codebridge_enterprise_stateless_jwt_secret_key_2026_secured');
    }

    /**
     * Test protected endpoints reject requests with missing Authorization header.
     */
    public function test_missing_auth_header_is_rejected(): void
    {
        $this->getJson('/api/v1/shifts/active')->assertStatus(401);
        $this->getJson('/api/v1/shifts/history')->assertStatus(401);
        $this->getJson('/api/v1/inventory/balances')->assertStatus(401);
        $this->postJson('/api/v1/shifts/open', [])->assertStatus(401);
    }

    /**
     * Test malformed bearer token is rejected.
     */
    public function test_malformed_jwt_is_rejected(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer completely_invalid_random_string',
        ])->getJson('/api/v1/shifts/active');

        $response->assertStatus(401);
    }

    /**
     * Test expired JWT token is rejected.
     */
    public function test_expired_jwt_is_rejected(): void
    {
        $expiredPayload = [
            'iss' => 'codebridge-auth-service',
            'sub' => '100',
            'name' => 'Cashier Chenda',
            'email' => 'chenda@codebridges.io',
            'role' => 'cashier',
            'tenant_id' => '1',
            'iat' => time() - 7200,
            'exp' => time() - 3600, // Expired 1 hour ago
        ];
        $token = JWT::encode($expiredPayload, $this->secret, 'HS256');

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->getJson('/api/v1/shifts/active');

        $response->assertStatus(401);
    }

    /**
     * Test forged signature is rejected.
     */
    public function test_forged_jwt_signature_is_rejected(): void
    {
        $attackerSecret = 'completely_different_untrusted_secret_key_12345';
        $payload = [
            'iss' => 'codebridge-auth-service',
            'sub' => '1',
            'name' => 'Attacker',
            'email' => 'attacker@malicious.com',
            'role' => 'super_admin',
            'tenant_id' => '1',
            'iat' => time(),
            'exp' => time() + 3600,
        ];
        $forgedToken = JWT::encode($payload, $attackerSecret, 'HS256');

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$forgedToken}",
        ])->getJson('/api/v1/shifts/active');

        $response->assertStatus(401);
    }

    /**
     * Test valid access token authenticates correctly.
     */
    public function test_valid_jwt_authenticates_cashier(): void
    {
        $user = User::factory()->create([
            'id' => 88,
            'role' => 'cashier',
        ]);

        $payload = [
            'iss' => 'codebridge-auth-service',
            'sub' => (string) $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => 'cashier',
            'tenant_id' => '1',
            'iat' => time(),
            'exp' => time() + 3600,
            'token_use' => 'access',
        ];
        $validToken = JWT::encode($payload, $this->secret, 'HS256');

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$validToken}",
        ])->getJson('/api/v1/shifts/active');

        $response->assertOk()
            ->assertJson([
                'status' => 'success',
            ]);
    }
}
