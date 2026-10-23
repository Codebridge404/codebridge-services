<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class JwtAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_returns_signed_jwt_token_and_claims(): void
    {
        $user = User::factory()->create([
            'email' => 'jwt_user@codebridges.io',
            'password' => Hash::make('SecretPass123!'),
            'role' => 'admin',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'jwt_user@codebridges.io',
            'password' => 'SecretPass123!',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'user',
                'token',
                'access_token',
                'token_type',
                'expires_in',
            ]);

        $token = $response->json('token');
        $this->assertEquals(2, substr_count($token, '.')); // Standard JWT 3 segments

        // Verify with JwtService
        $jwtService = app(JwtService::class);
        $payload = $jwtService->verify($token);

        $this->assertNotNull($payload);
        $this->assertEquals((string) $user->id, $payload['sub']);
        $this->assertEquals('jwt_user@codebridges.io', $payload['email']);
        $this->assertEquals('admin', $payload['role']);
    }

    public function test_protected_routes_accept_jwt_bearer_token(): void
    {
        $user = User::factory()->create([
            'role' => 'supervisor',
            'name' => 'Supervisor Alice',
        ]);

        $jwtService = app(JwtService::class);
        $jwtToken = $jwtService->generateTokenForUser($user);

        // Access /api/v1/me with JWT Bearer header
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$jwtToken}",
        ])->getJson('/api/v1/me');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'user' => [
                    'id' => $user->id,
                    'role' => 'supervisor',
                ],
            ]);
    }

    public function test_tampered_or_invalid_jwt_is_rejected(): void
    {
        $invalidJwt = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NTY3ODkwIiwibmFtZSI6IkpvaG4gRG9lIiwiaWF0IjoxNTE2MjM5MDIyfQ.tampered_signature';

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$invalidJwt}",
        ])->getJson('/api/v1/me');

        $response->assertStatus(401);
    }
}
