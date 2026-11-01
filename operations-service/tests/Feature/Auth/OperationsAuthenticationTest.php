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

    /**
     * Test refresh token purpose is strictly rejected from authenticating access endpoints.
     */
    public function test_refresh_token_cannot_authenticate_access_endpoints(): void
    {
        $payload = [
            'iss' => 'codebridge-auth-service',
            'sub' => '101',
            'name' => 'Cashier Chenda',
            'email' => 'chenda@codebridges.io',
            'role' => 'cashier',
            'tenant_id' => '1',
            'iat' => time(),
            'exp' => time() + 3600,
            'token_use' => 'refresh', // Explicit refresh token
        ];
        $refreshToken = JWT::encode($payload, $this->secret, 'HS256');

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$refreshToken}",
        ])->getJson('/api/v1/shifts/active');

        $response->assertStatus(401)
            ->assertJson([
                'status' => 'error',
                'code' => 'INVALID_TOKEN_PURPOSE',
            ]);
    }

    /**
     * Test untrusted JWT issuer is rejected.
     */
    public function test_untrusted_issuer_is_rejected(): void
    {
        $payload = [
            'iss' => 'untrusted-fake-auth-issuer',
            'sub' => '102',
            'name' => 'Malicious Actor',
            'role' => 'cashier',
            'tenant_id' => '1',
            'iat' => time(),
            'exp' => time() + 3600,
            'token_use' => 'access',
        ];
        $token = JWT::encode($payload, $this->secret, 'HS256');

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->getJson('/api/v1/shifts/active');

        $response->assertStatus(401);
    }

    /**
     * Test revoked JWT JTI is rejected.
     */
    public function test_revoked_jwt_jti_is_rejected(): void
    {
        $jti = 'revoked-test-jti-uuid-12345';
        \Illuminate\Support\Facades\Cache::put("revoked_jwt:{$jti}", true, 3600);

        $payload = [
            'iss' => 'codebridge-auth-service',
            'sub' => '103',
            'name' => 'Logged Out Cashier',
            'role' => 'cashier',
            'tenant_id' => '1',
            'iat' => time(),
            'exp' => time() + 3600,
            'token_use' => 'access',
            'jti' => $jti,
        ];
        $token = JWT::encode($payload, $this->secret, 'HS256');

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->getJson('/api/v1/shifts/active');

        $response->assertStatus(401)
            ->assertJson([
                'status' => 'error',
                'code' => 'TOKEN_REVOKED',
            ]);
    }

    /**
     * Test revoked user session invalidates previously issued tokens.
     */
    public function test_revoked_user_session_is_rejected(): void
    {
        $userId = '104';
        // User logged out or had sessions revoked at current timestamp
        \Illuminate\Support\Facades\Cache::put("revoked_user_sessions:{$userId}", time(), 3600);

        $payload = [
            'iss' => 'codebridge-auth-service',
            'sub' => $userId,
            'name' => 'Deactivated Cashier',
            'role' => 'cashier',
            'tenant_id' => '1',
            'iat' => time() - 60, // Issued 60 seconds before revocation
            'exp' => time() + 3600,
            'token_use' => 'access',
        ];
        $token = JWT::encode($payload, $this->secret, 'HS256');

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->getJson('/api/v1/shifts/active');

        $response->assertStatus(401)
            ->assertJson([
                'status' => 'error',
                'code' => 'SESSION_REVOKED',
            ]);
    }
}
