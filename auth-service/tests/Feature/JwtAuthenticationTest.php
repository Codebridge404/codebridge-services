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
                'access_token',
                'token_type',
                'expires_in',
            ]);

        $token = $response->json('access_token');
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

    public function test_login_returns_refresh_token(): void
    {
        $user = User::factory()->create([
            'email' => 'refresh_tester@codebridges.io',
            'password' => Hash::make('SecretPass123!'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'refresh_tester@codebridges.io',
            'password' => 'SecretPass123!',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'access_token',
                'refresh_token',
                'expires_in',
            ]);

        $refreshToken = $response->json('refresh_token');
        $this->assertNotEmpty($refreshToken);

        $jwtService = app(JwtService::class);
        $payload = $jwtService->verifyRefreshToken($refreshToken);
        $this->assertNotNull($payload);
        $this->assertEquals((string) $user->id, $payload['sub']);
        $this->assertEquals('refresh', $payload['token_use']);
    }

    public function test_can_refresh_access_token_and_rotate(): void
    {
        $user = User::factory()->create([
            'email' => 'refresh_rotation@codebridges.io',
            'password' => Hash::make('SecretPass123!'),
            'role' => 'supervisor',
        ]);

        $loginResponse = $this->postJson('/api/v1/auth/login', [
            'email' => 'refresh_rotation@codebridges.io',
            'password' => 'SecretPass123!',
        ]);

        $oldRefreshToken = $loginResponse->json('refresh_token');

        // Call /api/v1/auth/refresh
        $refreshResponse = $this->postJson('/api/v1/auth/refresh', [
            'refresh_token' => $oldRefreshToken,
        ]);

        $refreshResponse->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'access_token',
                'refresh_token',
                'expires_in',
                'user',
            ]);

        $newAccessToken = $refreshResponse->json('access_token');
        $newRefreshToken = $refreshResponse->json('refresh_token');

        $this->assertNotEquals($oldRefreshToken, $newRefreshToken);

        // Verify new access token works with protected route
        $meResponse = $this->withHeaders([
            'Authorization' => "Bearer {$newAccessToken}",
        ])->getJson('/api/v1/me');

        $meResponse->assertStatus(200)
            ->assertJson(['status' => 'success']);

        // Verify rotation: old refresh token cannot be reused
        $reusedResponse = $this->postJson('/api/v1/auth/refresh', [
            'refresh_token' => $oldRefreshToken,
        ]);

        $reusedResponse->assertStatus(401)
            ->assertJson([
                'status' => 'error',
                'code' => 'INVALID_REFRESH_TOKEN',
            ]);
    }

    public function test_refresh_token_validation_failures(): void
    {
        // Missing token
        $emptyResponse = $this->postJson('/api/v1/auth/refresh', []);
        $emptyResponse->assertStatus(422);

        // Tampered token
        $invalidResponse = $this->postJson('/api/v1/auth/refresh', [
            'refresh_token' => 'invalid.token.payload',
        ]);
        $invalidResponse->assertStatus(401);
    }

    public function test_refresh_token_cannot_be_used_to_authenticate_api_requests(): void
    {
        $user = User::factory()->create();
        $jwtService = app(JwtService::class);
        $refreshToken = $jwtService->generateRefreshTokenForUser($user);

        // Try to access protected API endpoint with refresh token
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$refreshToken}",
        ])->getJson('/api/v1/me');

        $response->assertStatus(401)
            ->assertJson([
                'status' => 'error',
                'code' => 'INVALID_TOKEN_TYPE',
            ]);
    }

    public function test_missing_user_with_signed_jwt_is_rejected(): void
    {
        $user = User::factory()->create();
        $jwtService = app(JwtService::class);
        $jwtToken = $jwtService->generateTokenForUser($user);

        // Now permanently delete the user from database
        $user->delete();

        // Attempt authentication with validly signed token for non-existent user
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$jwtToken}",
        ])->getJson('/api/v1/me');

        $response->assertStatus(401)
            ->assertJson([
                'status' => 'error',
                'code' => 'USER_NOT_FOUND',
            ]);
    }
}

