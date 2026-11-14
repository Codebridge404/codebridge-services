<?php

namespace Tests\Feature;

use App\Models\ApiClient;
use App\Models\User;
use App\Services\JwtService;
use App\Services\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TwoFactorHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected TwoFactorService $twoFactorService;
    protected JwtService $jwtService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->twoFactorService = app(TwoFactorService::class);
        $this->jwtService = app(JwtService::class);

        ApiClient::firstOrCreate(
            ['client_key' => 'client_web_admin_cb2026'],
            [
                'uuid' => (string) \Illuminate\Support\Str::uuid(),
                'name' => 'Default Platform Client',
                'client_type' => 'first_party',
                'is_active' => true,
            ]
        );
    }

    /**
     * Scenario 01:
     * Password login with TOTP enabled returns 403 / requires_two_factor=true,
     * challenge_id, no access/refresh token in body or cookies.
     */
    public function test_scenario_01_password_login_with_totp_returns_challenge_id_and_no_tokens(): void
    {
        $password = 'Secret2FA!2026';
        $user = User::factory()->create([
            'email' => 'totp_hardening_01@codebridges.io',
            'password' => Hash::make($password),
            'two_factor_enabled' => true,
            'is_active' => true,
        ]);

        $secret = $this->twoFactorService->generateSecretKey(32);
        $this->twoFactorService->storeSecret($user, $secret);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'totp_hardening_01@codebridges.io',
            'password' => $password,
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('status', 'requires_mfa')
            ->assertJsonPath('code', 'TWO_FACTOR_REQUIRED')
            ->assertJsonPath('requires_two_factor', true)
            ->assertJsonMissing(['access_token', 'refresh_token', 'sanctum_token']);

        $challengeId = $response->json('challenge_id');
        $this->assertNotEmpty($challengeId);
        $this->assertEquals($challengeId, $response->json('challenge_token'));

        // No authorization cookies set
        $this->assertEmpty($response->headers->getCookies());
    }

    /**
     * Scenario 02:
     * Attempting to use the challenge_id as a Bearer access token on /me returns 401.
     */
    public function test_scenario_02_challenge_id_cannot_be_used_as_bearer_token(): void
    {
        $password = 'Secret2FA!2026';
        $user = User::factory()->create([
            'email' => 'totp_hardening_02@codebridges.io',
            'password' => Hash::make($password),
            'two_factor_enabled' => true,
            'is_active' => true,
        ]);

        $secret = $this->twoFactorService->generateSecretKey(32);
        $this->twoFactorService->storeSecret($user, $secret);

        $loginRes = $this->postJson('/api/v1/auth/login', [
            'email' => 'totp_hardening_02@codebridges.io',
            'password' => $password,
        ]);
        $challengeId = $loginRes->json('challenge_id');

        // Using challenge_id as Bearer token on protected /api/v1/me endpoint
        $meRes = $this->withHeader('Authorization', 'Bearer ' . $challengeId)
            ->getJson('/api/v1/me');

        $meRes->assertStatus(401);
    }

    /**
     * Scenario 03:
     * Submitting the correct TOTP code with challenge_id returns 200 with
     * access_token, refresh_token (rt_...), session_uuid, and correct amr claims.
     */
    public function test_scenario_03_correct_totp_issues_session_rotating_refresh_token_and_amr_claims(): void
    {
        $password = 'Secret2FA!2026';
        $user = User::factory()->create([
            'email' => 'totp_hardening_03@codebridges.io',
            'password' => Hash::make($password),
            'two_factor_enabled' => true,
            'is_active' => true,
        ]);

        $secret = $this->twoFactorService->generateSecretKey(32);
        $this->twoFactorService->storeSecret($user, $secret);

        $loginRes = $this->postJson('/api/v1/auth/login', [
            'email' => 'totp_hardening_03@codebridges.io',
            'password' => $password,
        ]);
        $challengeId = $loginRes->json('challenge_id');

        $totpCode = $this->twoFactorService->calculateCode($secret);

        // Submit via challenge_id
        $challengeRes = $this->postJson('/api/v1/auth/two-factor/challenge', [
            'challenge_id' => $challengeId,
            'code' => $totpCode,
        ]);

        $challengeRes->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure([
                'access_token',
                'refresh_token',
                'session_uuid',
                'session_id',
                'user',
            ]);

        $refreshToken = $challengeRes->json('refresh_token');
        $this->assertStringStartsWith('rt_', $refreshToken);

        $sessionUuid = $challengeRes->json('session_uuid');
        $this->assertNotEmpty($sessionUuid);

        // Verify JWT amr claims
        $jwtPayload = $this->jwtService->verify($challengeRes->json('access_token'));
        $this->assertNotNull($jwtPayload);
        $this->assertContains('pwd', $jwtPayload['amr'] ?? []);
        $this->assertContains('mfa', $jwtPayload['amr'] ?? []);
        $this->assertEquals($sessionUuid, $jwtPayload['sid'] ?? null);

        // Verify session recorded in database
        $this->assertDatabaseHas('user_sessions', [
            'uuid' => $sessionUuid,
            'user_id' => $user->id,
            'revoked_at' => null,
        ]);
    }

    /**
     * Scenario 04:
     * Submitting an invalid TOTP code returns 422/401; 5th failure locks the challenge.
     */
    public function test_scenario_04_invalid_totp_code_locks_out_after_repeated_failures(): void
    {
        $password = 'Secret2FA!2026';
        $user = User::factory()->create([
            'email' => 'totp_hardening_04@codebridges.io',
            'password' => Hash::make($password),
            'two_factor_enabled' => true,
            'is_active' => true,
        ]);

        $secret = $this->twoFactorService->generateSecretKey(32);
        $this->twoFactorService->storeSecret($user, $secret);

        $loginRes = $this->postJson('/api/v1/auth/login', [
            'email' => 'totp_hardening_04@codebridges.io',
            'password' => $password,
        ]);
        $challengeId = $loginRes->json('challenge_id');

        $lockedResponse = null;
        for ($i = 1; $i <= 6; $i++) {
            $res = $this->postJson('/api/v1/auth/two-factor/challenge', [
                'challenge_id' => $challengeId,
                'code' => '999999',
            ]);

            if ($res->getStatusCode() === 429) {
                $lockedResponse = $res;
                break;
            }

            $res->assertStatus(422);
        }

        $this->assertNotNull($lockedResponse);
        $lockedResponse->assertStatus(429)
            ->assertJsonPath('code', 'ACCOUNT_LOCKED_OUT');
    }

    /**
     * Scenario 05:
     * Replaying the exact same TOTP code within the 30-second window is rejected.
     */
    public function test_scenario_05_replaying_same_totp_code_within_30_second_window_is_rejected(): void
    {
        $password = 'Secret2FA!2026';
        $user = User::factory()->create([
            'email' => 'totp_hardening_05@codebridges.io',
            'password' => Hash::make($password),
            'two_factor_enabled' => true,
            'is_active' => true,
        ]);

        $secret = $this->twoFactorService->generateSecretKey(32);
        $this->twoFactorService->storeSecret($user, $secret);
        $totpCode = $this->twoFactorService->calculateCode($secret);

        // 1. First challenge verification succeeds
        $login1 = $this->postJson('/api/v1/auth/login', [
            'email' => 'totp_hardening_05@codebridges.io',
            'password' => $password,
        ]);
        $res1 = $this->postJson('/api/v1/auth/two-factor/challenge', [
            'challenge_id' => $login1->json('challenge_id'),
            'code' => $totpCode,
        ]);
        $res1->assertStatus(200);

        // 2. Second login in same 30s window presents the exact same TOTP code -> REJECTED
        $login2 = $this->postJson('/api/v1/auth/login', [
            'email' => 'totp_hardening_05@codebridges.io',
            'password' => $password,
        ]);
        $res2 = $this->postJson('/api/v1/auth/two-factor/challenge', [
            'challenge_id' => $login2->json('challenge_id'),
            'code' => $totpCode,
        ]);

        $res2->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR');
    }

    /**
     * Scenario 06:
     * Replaying the exact same recovery code is rejected.
     */
    public function test_scenario_06_replaying_same_recovery_code_is_rejected(): void
    {
        $password = 'Secret2FA!2026';
        $user = User::factory()->create([
            'email' => 'totp_hardening_06@codebridges.io',
            'password' => Hash::make($password),
            'two_factor_enabled' => true,
            'is_active' => true,
        ]);

        $secret = $this->twoFactorService->generateSecretKey(32);
        $this->twoFactorService->storeSecret($user, $secret);
        $this->twoFactorService->storeRecoveryCodes($user, ['BACKUP-AAAA-1111', 'BACKUP-BBBB-2222']);

        // 1. First use consumes BACKUP-AAAA-1111
        $login1 = $this->postJson('/api/v1/auth/login', [
            'email' => 'totp_hardening_06@codebridges.io',
            'password' => $password,
        ]);
        $res1 = $this->postJson('/api/v1/auth/two-factor/challenge', [
            'challenge_id' => $login1->json('challenge_id'),
            'code' => 'BACKUP-AAAA-1111',
        ]);
        $res1->assertStatus(200);

        // 2. Second use of same recovery code is rejected
        $login2 = $this->postJson('/api/v1/auth/login', [
            'email' => 'totp_hardening_06@codebridges.io',
            'password' => $password,
        ]);
        $res2 = $this->postJson('/api/v1/auth/two-factor/challenge', [
            'challenge_id' => $login2->json('challenge_id'),
            'code' => 'BACKUP-AAAA-1111',
        ]);
        $res2->assertStatus(422);
    }

    /**
     * Scenario 07:
     * Concurrent requests with the same challenge_id: exactly one succeeds, the other fails.
     */
    public function test_scenario_07_concurrent_challenge_consumption_permits_only_one_success(): void
    {
        $password = 'Secret2FA!2026';
        $user = User::factory()->create([
            'email' => 'totp_hardening_07@codebridges.io',
            'password' => Hash::make($password),
            'two_factor_enabled' => true,
            'is_active' => true,
        ]);

        $secret = $this->twoFactorService->generateSecretKey(32);
        $this->twoFactorService->storeSecret($user, $secret);
        $totpCode = $this->twoFactorService->calculateCode($secret);

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => 'totp_hardening_07@codebridges.io',
            'password' => $password,
        ]);
        $challengeId = $login->json('challenge_id');

        // First attempt consumes the challenge token
        $res1 = $this->postJson('/api/v1/auth/two-factor/challenge', [
            'challenge_id' => $challengeId,
            'code' => $totpCode,
        ]);
        $res1->assertStatus(200);

        // Second attempt with same challenge_id fails because challenge was atomically pulled
        $res2 = $this->postJson('/api/v1/auth/two-factor/challenge', [
            'challenge_id' => $challengeId,
            'code' => $totpCode,
        ]);
        $res2->assertStatus(422);
    }
}
