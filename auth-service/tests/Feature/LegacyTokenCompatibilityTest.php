<?php

namespace Tests\Feature;

use App\Models\Auth\User;
use App\Services\JwtService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class LegacyTokenCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private JwtService $jwtService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->jwtService = app(JwtService::class);
        $this->user = User::factory()->create([
            'email' => 'legacy_token_test@codebridges.io',
            'password' => Hash::make('SecretPass123!'),
            'role' => 'admin',
        ]);
    }

    /**
     * Helper to create a personal_access_tokens row with explicit tokenable_type.
     */
    private function createTokenRow(string $tokenableType, ?Carbon $expiresAt = null, ?Carbon $sessionExpiresAt = null): array
    {
        $plainToken = Str::random(40);
        $hash = hash('sha256', $plainToken);

        $data = [
            'name' => 'legacy_device_session',
            'token' => $hash,
            'abilities' => json_encode(['*']),
            'tokenable_type' => $tokenableType,
            'tokenable_id' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
            'last_used_at' => now(),
        ];

        if ($expiresAt !== null) {
            $data['expires_at'] = $expiresAt;
        }

        if (Schema::hasColumn('personal_access_tokens', 'session_expires_at')) {
            $data['session_expires_at'] = $sessionExpiresAt;
        }

        $id = DB::table('personal_access_tokens')->insertGetId($data);

        return [
            'id' => $id,
            'plain_token' => $plainToken,
            'bearer_header' => "{$id}|{$plainToken}",
        ];
    }

    /**
     * 1. Sanctum Bearer authentication with legacy 'App\Models\User' tokenable_type.
     */
    public function test_bearer_authentication_succeeds_with_legacy_tokenable_type(): void
    {
        $tokenData = $this->createTokenRow('App\Models\User');

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$tokenData['bearer_header'],
        ])->postJson('/api/v1/auth/sessions/ping');

        $response->assertStatus(200);
        $this->assertTrue($response->json('data.is_active'));
    }

    /**
     * 2. JWT Bearer authentication with numeric session ID referencing legacy tokenable_type.
     */
    public function test_jwt_authentication_resolves_numeric_session_with_legacy_tokenable_type(): void
    {
        $tokenData = $this->createTokenRow('App\Models\User');

        $jwt = $this->jwtService->generateTokenForUser($this->user, [
            'sid' => (string) $tokenData['id'],
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$jwt,
        ])->postJson('/api/v1/auth/sessions/ping');

        $response->assertStatus(200);
        $this->assertTrue($response->json('data.is_active'));
    }

    /**
     * 3. Numeric-session JWT refresh flow works with legacy tokenable_type.
     */
    public function test_jwt_refresh_succeeds_with_legacy_tokenable_type_session(): void
    {
        $tokenData = $this->createTokenRow('App\Models\User');

        $refreshToken = $this->jwtService->generateRefreshTokenForUser($this->user, [
            'sid' => (string) $tokenData['id'],
        ]);

        $response = $this->postJson('/api/v1/auth/refresh', [
            'refresh_token' => $refreshToken,
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'access_token',
                'refresh_token',
            ]);

        $newAccessToken = $response->json('access_token');
        $this->assertNotEmpty($newAccessToken);

        // Verify the newly issued access token authenticates correctly
        $pingResponse = $this->withHeaders([
            'Authorization' => 'Bearer '.$newAccessToken,
        ])->postJson('/api/v1/auth/sessions/ping');

        $pingResponse->assertStatus(200);
        $this->assertTrue($pingResponse->json('data.is_active'));
    }

    /**
     * 4. Expired legacy tokens remain strictly rejected.
     */
    public function test_expired_legacy_tokens_remain_rejected(): void
    {
        // 4a. Expired Sanctum token
        $expiredToken = $this->createTokenRow(
            'App\Models\User',
            expiresAt: now()->subMinutes(30)
        );

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$expiredToken['bearer_header'],
        ])->postJson('/api/v1/auth/sessions/ping');

        $response->assertStatus(401);

        // 4b. Expired custom session_expires_at with JWT numeric session
        if (Schema::hasColumn('personal_access_tokens', 'session_expires_at')) {
            $expiredSessionToken = $this->createTokenRow(
                'App\Models\User',
                sessionExpiresAt: now()->subMinutes(10)
            );

            $jwt = $this->jwtService->generateTokenForUser($this->user, [
                'sid' => (string) $expiredSessionToken['id'],
            ]);

            $jwtResponse = $this->withHeaders([
                'Authorization' => 'Bearer '.$jwt,
            ])->postJson('/api/v1/auth/sessions/ping');

            $jwtResponse->assertStatus(401);
        }
    }

    /**
     * 5. Logout properly terminates legacy token sessions.
     */
    public function test_logout_terminates_legacy_token(): void
    {
        $tokenData = $this->createTokenRow('App\Models\User');

        $logoutResponse = $this->withHeaders([
            'Authorization' => 'Bearer '.$tokenData['bearer_header'],
        ])->postJson('/api/v1/auth/logout');

        $logoutResponse->assertStatus(200);

        // Verify the token is gone from DB
        $exists = DB::table('personal_access_tokens')->where('id', $tokenData['id'])->exists();
        $this->assertFalse($exists);

        // Verify subsequent requests are rejected
        $subsequentResponse = $this->withHeaders([
            'Authorization' => 'Bearer '.$tokenData['bearer_header'],
        ])->postJson('/api/v1/auth/sessions/ping');

        $subsequentResponse->assertStatus(401);
    }

    /**
     * 6. Logout with JWT having numeric session ID terminates legacy token.
     */
    public function test_jwt_logout_terminates_legacy_numeric_session_token(): void
    {
        $tokenData = $this->createTokenRow('App\Models\User');

        $jwt = $this->jwtService->generateTokenForUser($this->user, [
            'sid' => (string) $tokenData['id'],
        ]);

        $refreshToken = $this->jwtService->generateRefreshTokenForUser($this->user, [
            'sid' => (string) $tokenData['id'],
        ]);

        $logoutResponse = $this->withHeaders([
            'Authorization' => 'Bearer '.$jwt,
        ])->postJson('/api/v1/auth/logout');

        $logoutResponse->assertStatus(200);

        // Verify token in database is deleted
        $exists = DB::table('personal_access_tokens')->where('id', $tokenData['id'])->exists();
        $this->assertFalse($exists);

        // Verify revoked JWT access token is rejected
        $this->withHeaders([
            'Authorization' => 'Bearer '.$jwt,
        ])->postJson('/api/v1/auth/sessions/ping')->assertStatus(401);

        // Verify refresh token for revoked legacy numeric session is rejected
        $this->postJson('/api/v1/auth/refresh', [
            'refresh_token' => $refreshToken,
        ])->assertStatus(401);
    }

    /**
     * 7. Sessions listing includes legacy tokens and manual revocation deletes it.
     */
    public function test_sessions_listing_and_manual_revocation_supports_legacy_tokens(): void
    {
        $tokenData = $this->createTokenRow('App\Models\User');

        $listResponse = $this->withHeaders([
            'Authorization' => 'Bearer '.$tokenData['bearer_header'],
        ])->getJson('/api/v1/auth/sessions');

        $listResponse->assertStatus(200);
        $sessions = $listResponse->json('data') ?? [];
        $found = collect($sessions)->contains(fn ($s) => (string) ($s['id'] ?? '') === (string) $tokenData['id']);
        $this->assertTrue($found, 'Legacy token session was not found in sessions listing');

        // Revoke the session via DELETE /api/v1/auth/sessions/{id}
        $revokeResponse = $this->withHeaders([
            'Authorization' => 'Bearer '.$tokenData['bearer_header'],
        ])->deleteJson('/api/v1/auth/sessions/'.$tokenData['id']);

        $revokeResponse->assertStatus(200);

        // Confirm token was deleted
        $exists = DB::table('personal_access_tokens')->where('id', $tokenData['id'])->exists();
        $this->assertFalse($exists);
    }

    /**
     * 8. Verify the migration updates legacy tokenable_type to modern namespace.
     */
    public function test_migration_migrates_legacy_user_tokenable_type(): void
    {
        $tokenData = $this->createTokenRow('App\Models\User');

        $migration = require database_path('migrations/2026_11_17_000001_migrate_legacy_user_tokenable_type.php');
        $migration->up();

        $updatedType = DB::table('personal_access_tokens')->where('id', $tokenData['id'])->value('tokenable_type');
        $this->assertSame(User::class, $updatedType);
    }
}
