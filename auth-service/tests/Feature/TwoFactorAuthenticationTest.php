<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TwoFactorAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected TwoFactorService $twoFactorService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->twoFactorService = app(TwoFactorService::class);
    }

    public function test_2fa_setup_requires_authentication(): void
    {
        $this->postJson('/api/v1/auth/2fa/setup')->assertStatus(401);
        $this->postJson('/api/v1/auth/2fa/confirm', ['code' => '123456'])->assertStatus(401);
        $this->postJson('/api/v1/auth/2fa/disable', ['password' => 'secret'])->assertStatus(401);
    }

    public function test_user_can_setup_2fa_and_receive_secret_and_qr_code(): void
    {
        $user = User::factory()->create([
            'email' => 'twofactor.setup@codebridge.dev',
        ]);

        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson('/api/v1/auth/2fa/setup');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
            ])
            ->assertJsonStructure([
                'status',
                'message',
                'secret',
                'setup_uri',
                'qr_code_url',
            ]);

        $secret = $response->json('secret');
        $this->assertNotEmpty($secret);
        $this->assertEquals(32, strlen($secret));

        $setupUri = $response->json('setup_uri');
        $this->assertStringStartsWith('otpauth://totp/', $setupUri);
        $this->assertStringContainsString('twofactor.setup%40codebridge.dev', $setupUri);

        $qrCodeUrl = $response->json('qr_code_url');
        $this->assertStringStartsWith('data:image/svg+xml', $qrCodeUrl);

        // 2FA remains not enabled until confirmed
        $user->refresh();
        $this->assertFalse((bool) $user->two_factor_enabled);
        $this->assertNotNull($user->two_factor_secret);
    }

    public function test_user_can_confirm_2fa_with_valid_totp_code(): void
    {
        $user = User::factory()->create([
            'email' => 'twofactor.confirm@codebridge.dev',
        ]);

        Sanctum::actingAs($user, ['*']);

        // 1. Setup
        $setupRes = $this->postJson('/api/v1/auth/2fa/setup');
        $secret = $setupRes->json('secret');

        // 2. Generate valid TOTP code
        $validCode = $this->twoFactorService->calculateCode($secret);

        // 3. Confirm
        $confirmRes = $this->postJson('/api/v1/auth/2fa/confirm', [
            'code' => $validCode,
        ]);

        $confirmRes->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'two_factor_enabled' => true,
            ])
            ->assertJsonStructure([
                'status',
                'message',
                'two_factor_enabled',
                'recovery_codes',
            ]);

        $recoveryCodes = $confirmRes->json('recovery_codes');
        $this->assertIsArray($recoveryCodes);
        $this->assertCount(8, $recoveryCodes);

        $user->refresh();
        $this->assertTrue((bool) $user->two_factor_enabled);
        $this->assertNotNull($user->two_factor_confirmed_at);
        $this->assertNotNull($user->two_factor_recovery_codes);
    }

    public function test_confirm_rejects_invalid_totp_code(): void
    {
        $user = User::factory()->create([
            'email' => 'twofactor.invalid@codebridge.dev',
        ]);

        Sanctum::actingAs($user, ['*']);

        $this->postJson('/api/v1/auth/2fa/setup');

        $response = $this->postJson('/api/v1/auth/2fa/confirm', [
            'code' => '000000',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'status' => 'error',
            ]);

        $user->refresh();
        $this->assertFalse((bool) $user->two_factor_enabled);
    }

    public function test_login_with_2fa_enabled_returns_challenge_token_and_no_access_token(): void
    {
        $password = 'SuperSecurePass123!';
        $user = User::factory()->create([
            'email' => 'twofactor.login@codebridge.dev',
            'password' => Hash::make($password),
            'two_factor_enabled' => true,
            'is_active' => true,
        ]);

        $secret = $this->twoFactorService->generateSecretKey(16);
        $this->twoFactorService->storeSecret($user, $secret);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'twofactor.login@codebridge.dev',
            'password' => $password,
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'status' => 'requires_mfa',
                'code' => 'TWO_FACTOR_REQUIRED',
                'two_factor_required' => true,
            ])
            ->assertJsonMissing(['access_token', 'sanctum_token', 'refresh_token']);

        $challengeToken = $response->json('challenge_token');
        $this->assertNotNull($challengeToken);
        $this->assertNotEmpty($challengeToken);
    }

    public function test_user_can_complete_2fa_challenge_with_totp_code(): void
    {
        $password = 'SecretLogin2026!';
        $user = User::factory()->create([
            'email' => 'twofactor.challenge@codebridge.dev',
            'password' => Hash::make($password),
            'two_factor_enabled' => true,
            'is_active' => true,
        ]);

        $secret = $this->twoFactorService->generateSecretKey(16);
        $this->twoFactorService->storeSecret($user, $secret);

        // 1. Initial Login
        $loginRes = $this->postJson('/api/v1/auth/login', [
            'email' => 'twofactor.challenge@codebridge.dev',
            'password' => $password,
        ]);
        $loginRes->assertStatus(403);
        $challengeToken = $loginRes->json('challenge_token');

        // 2. Submit Challenge with TOTP code
        $totpCode = $this->twoFactorService->calculateCode($secret);

        $challengeRes = $this->postJson('/api/v1/auth/2fa/challenge', [
            'challenge_token' => $challengeToken,
            'code' => $totpCode,
        ]);

        $challengeRes->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'token_type' => 'Bearer',
            ])
            ->assertJsonStructure([
                'status',
                'message',
                'access_token',
                'refresh_token',
                'token_type',
                'expires_in',
                'user',
            ]);

        $this->assertNotEmpty($challengeRes->json('access_token'));
        $this->assertNotEmpty($challengeRes->json('refresh_token'));

        // Challenge token is consumed and cannot be reused
        $reuseRes = $this->postJson('/api/v1/auth/2fa/challenge', [
            'challenge_token' => $challengeToken,
            'code' => $totpCode,
        ]);
        $reuseRes->assertStatus(422);
    }

    public function test_user_can_complete_2fa_challenge_with_recovery_code(): void
    {
        $password = 'RecoveryPass2026!';
        $user = User::factory()->create([
            'email' => 'twofactor.recovery@codebridge.dev',
            'password' => Hash::make($password),
            'two_factor_enabled' => true,
            'is_active' => true,
        ]);

        $secret = $this->twoFactorService->generateSecretKey(16);
        $this->twoFactorService->storeSecret($user, $secret);

        $recoveryCodes = ['RECV-1111', 'RECV-2222', 'RECV-3333'];
        $this->twoFactorService->storeRecoveryCodes($user, $recoveryCodes);

        // 1. Initial Login
        $loginRes = $this->postJson('/api/v1/auth/login', [
            'email' => 'twofactor.recovery@codebridge.dev',
            'password' => $password,
        ]);
        $loginRes->assertStatus(403);
        $challengeToken = $loginRes->json('challenge_token');

        // 2. Submit Challenge with one recovery code
        $challengeRes = $this->postJson('/api/v1/auth/2fa/challenge', [
            'challenge_token' => $challengeToken,
            'code' => 'RECV-1111',
        ]);

        $challengeRes->assertStatus(200)
            ->assertJson([
                'status' => 'success',
            ]);

        $user->refresh();
        $remainingCodes = $this->twoFactorService->getRecoveryCodes($user);
        $this->assertCount(2, $remainingCodes);
        $this->assertNotContains('RECV-1111', $remainingCodes);
        $this->assertContains('RECV-2222', $remainingCodes);
    }

    public function test_challenge_rejects_invalid_or_expired_token(): void
    {
        $response = $this->postJson('/api/v1/auth/2fa/challenge', [
            'challenge_token' => 'non_existent_token_12345',
            'code' => '123456',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'status' => 'error',
            ]);
    }

    public function test_user_can_disable_2fa_with_password(): void
    {
        $password = 'DisablePassword123!';
        $user = User::factory()->create([
            'email' => 'twofactor.disable@codebridge.dev',
            'password' => Hash::make($password),
            'two_factor_enabled' => true,
            'two_factor_secret' => 'some_secret',
        ]);

        Sanctum::actingAs($user, ['*']);

        // Wrong password fails
        $failRes = $this->postJson('/api/v1/auth/2fa/disable', [
            'password' => 'WrongPassword!',
        ]);
        $failRes->assertStatus(422);

        $user->refresh();
        $this->assertTrue((bool) $user->two_factor_enabled);

        // Correct password succeeds
        $successRes = $this->postJson('/api/v1/auth/2fa/disable', [
            'password' => $password,
        ]);

        $successRes->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'two_factor_enabled' => false,
            ]);

        $user->refresh();
        $this->assertFalse((bool) $user->two_factor_enabled);
        $this->assertNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_recovery_codes);
    }

    public function test_toggle_backward_compatibility(): void
    {
        $password = 'TogglePassword123!';
        $user = User::factory()->create([
            'email' => 'twofactor.toggle@codebridge.dev',
            'password' => Hash::make($password),
            'two_factor_enabled' => true,
        ]);

        Sanctum::actingAs($user, ['*']);

        // Enable attempt via toggle instructs to use setup/confirm
        $enableRes = $this->postJson('/api/v1/auth/2fa/toggle', [
            'enable' => true,
        ]);
        $enableRes->assertStatus(422)
            ->assertJson([
                'code' => 'TWO_FACTOR_ENROLLMENT_REQUIRED',
            ]);

        // Disable via toggle with password succeeds
        $disableRes = $this->postJson('/api/v1/auth/2fa/toggle', [
            'enable' => false,
            'password' => $password,
        ]);
        $disableRes->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'two_factor_enabled' => false,
            ]);
    }

    public function test_2fa_reconfiguration_requires_password_and_preserves_existing_secret_until_confirmed(): void
    {
        $password = 'SecretPass123!';
        $user = User::factory()->create([
            'password' => Hash::make($password),
            'two_factor_enabled' => true,
        ]);

        $initialSecret = $this->twoFactorService->generateSecretKey(32);
        $this->twoFactorService->storeSecret($user, $initialSecret);

        Sanctum::actingAs($user, ['*']);

        // 1. Setup without password when 2FA is enabled fails
        $unauthSetup = $this->postJson('/api/v1/auth/2fa/setup');
        $unauthSetup->assertStatus(422)
            ->assertJson([
                'code' => 'INVALID_CREDENTIALS',
            ]);

        // Existing secret in database is untouched!
        $this->assertEquals($initialSecret, $this->twoFactorService->getDecryptedSecret($user));

        // 2. Setup with valid password succeeds and returns a new pending secret
        $validSetup = $this->postJson('/api/v1/auth/2fa/setup', [
            'password' => $password,
        ]);
        $validSetup->assertStatus(200);
        $replacementSecret = $validSetup->json('secret');
        $this->assertNotEquals($initialSecret, $replacementSecret);

        // Before confirmation, the active secret is STILL the initial secret
        $this->assertEquals($initialSecret, $this->twoFactorService->getDecryptedSecret($user));

        // 3. Confirm with code from replacement secret successfully updates
        $code = $this->twoFactorService->calculateCode($replacementSecret);
        $confirmRes = $this->postJson('/api/v1/auth/2fa/confirm', [
            'code' => $code,
        ]);
        $confirmRes->assertStatus(200);

        // Now the secret is updated
        $this->assertEquals($replacementSecret, $this->twoFactorService->getDecryptedSecret($user));
    }
}

