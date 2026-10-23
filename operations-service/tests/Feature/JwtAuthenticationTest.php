<?php

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Models\Shift;
use App\Models\User;
use App\Services\JwtService;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JwtAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_protected_currencies_endpoint_authenticates_statelessly_with_jwt(): void
    {
        $secret = config('jwt.secret', 'codebridge_enterprise_stateless_jwt_secret_key_2026_secured');

        // Create active exchange rate record
        ExchangeRate::create([
            'base_currency' => 'USD',
            'target_currency' => 'KHR',
            'rate' => 4110.0000,
            'source' => 'NBC_LIVE',
            'effective_date' => now()->toDateString(),
            'is_active' => true,
        ]);

        // Generate signed JWT token directly simulating Auth Service token
        $jwtPayload = [
            'iss' => 'codebridge-auth-service',
            'sub' => '42',
            'name' => 'Cashier Chenda',
            'email' => 'chenda@codebridges.io',
            'role' => 'cashier',
            'tenant_id' => '1',
            'outlet_id' => '1',
            'iat' => time(),
            'exp' => time() + 3600,
        ];
        $jwtToken = JWT::encode($jwtPayload, $secret, 'HS256');

        // Call /api/v1/currencies/exchange-rate/active with JWT
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$jwtToken}",
        ])->getJson('/api/v1/currencies/exchange-rate/active');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'active_rate' => 4110,
                ],
            ]);
    }

    public function test_protected_shifts_endpoint_authenticates_with_jwt(): void
    {
        $secret = config('jwt.secret', 'codebridge_enterprise_stateless_jwt_secret_key_2026_secured');

        $user = User::factory()->create([
            'id' => 77,
            'name' => 'Cashier Sreymom',
            'role' => 'cashier',
        ]);

        $jwtPayload = [
            'iss' => 'codebridge-auth-service',
            'sub' => (string) $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => 'cashier',
            'tenant_id' => '1',
            'outlet_id' => '1',
            'iat' => time(),
            'exp' => time() + 3600,
        ];
        $jwtToken = JWT::encode($jwtPayload, $secret, 'HS256');

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$jwtToken}",
        ])->getJson('/api/v1/shifts/history');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'data' => [
                    'shifts',
                ],
            ]);
    }

    public function test_forged_signature_jwt_is_blocked(): void
    {
        $forgedSecret = 'completely_wrong_secret_key_with_at_least_32_bytes!';
        $jwtPayload = [
            'iss' => 'codebridge-auth-service',
            'sub' => '999',
            'name' => 'Attacker',
            'email' => 'attacker@hack.com',
            'role' => 'super_admin',
            'iat' => time(),
            'exp' => time() + 3600,
        ];
        $forgedJwt = JWT::encode($jwtPayload, $forgedSecret, 'HS256');

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$forgedJwt}",
        ])->getJson('/api/v1/currencies/exchange-rate/active');

        $response->assertStatus(401);
    }
}
