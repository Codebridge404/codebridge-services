<?php

namespace Tests\Feature;

use App\Models\ApiClient;
use App\Models\PasskeyCredential;
use App\Models\User;
use App\Services\JwtService;
use App\Services\PasskeyService;
use CBOR\ByteStringObject;
use CBOR\MapObject;
use CBOR\NegativeIntegerObject;
use CBOR\TextStringObject;
use CBOR\UnsignedIntegerObject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PasskeyAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected PasskeyService $passkeyService;
    protected JwtService $jwtService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->passkeyService = app(PasskeyService::class);
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
     * Helper to generate an EC P-256 key pair and return the private key PEM,
     * raw public key coordinates (x, y), and CBOR-encoded COSE public key bytes.
     */
    protected function generateEcKeyPair(): array
    {
        $keyPair = openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);
        openssl_pkey_export($keyPair, $privKeyPem);
        $details = openssl_pkey_get_details($keyPair);
        $x = $details['ec']['x'];
        $y = $details['ec']['y'];

        $cose = MapObject::create()
            ->add(UnsignedIntegerObject::create(1), UnsignedIntegerObject::create(2)) // kty = EC2
            ->add(UnsignedIntegerObject::create(3), NegativeIntegerObject::create(-7)) // alg = ES256
            ->add(NegativeIntegerObject::create(-1), UnsignedIntegerObject::create(1)) // crv = P-256
            ->add(NegativeIntegerObject::create(-2), ByteStringObject::create($x))
            ->add(NegativeIntegerObject::create(-3), ByteStringObject::create($y));

        return [
            'private_key' => $privKeyPem,
            'x' => $x,
            'y' => $y,
            'cose_bytes' => (string) $cose,
        ];
    }

    /**
     * Helper to create a WebAuthn attestation object (fmt = none).
     */
    protected function craftAttestationObject(string $credIdRaw, string $coseBytes, bool $userVerified = true): string
    {
        $rpIdHash = hash('sha256', 'localhost', true);
        $flags = 0x41; // UP (0x01) + AT (0x40)
        if ($userVerified) {
            $flags |= 0x04; // UV (0x04)
        }

        $authData = $rpIdHash
            . chr($flags)
            . pack('N', 0) // counter = 0
            . str_repeat("\0", 16) // aaguid
            . pack('n', strlen($credIdRaw))
            . $credIdRaw
            . $coseBytes;

        $attMap = MapObject::create()
            ->add(TextStringObject::create('fmt'), TextStringObject::create('none'))
            ->add(TextStringObject::create('attStmt'), MapObject::create())
            ->add(TextStringObject::create('authData'), ByteStringObject::create($authData));

        return (string) $attMap;
    }

    /**
     * Helper to craft a WebAuthn assertion authenticatorData.
     */
    protected function craftAssertionAuthData(int $counter = 1, bool $userVerified = true): string
    {
        $rpIdHash = hash('sha256', 'localhost', true);
        $flags = 0x01; // UP
        if ($userVerified) {
            $flags |= 0x04; // UV
        }

        return $rpIdHash . chr($flags) . pack('N', $counter);
    }

    public function test_platform_association_endpoints_return_valid_json_and_headers(): void
    {
        // 1. Android Asset Links
        $androidRes = $this->get('/.well-known/assetlinks.json');
        $androidRes->assertStatus(200)
            ->assertHeader('Content-Type', 'application/json');

        $androidData = $androidRes->json();
        $this->assertIsArray($androidData);
        $this->assertNotEmpty($androidData);
        $this->assertEquals('android_app', $androidData[0]['target']['namespace']);
        $this->assertEquals('com.codebridge.pos', $androidData[0]['target']['package_name']);
        $this->assertIsArray($androidData[0]['target']['sha256_cert_fingerprints']);

        // 2. Apple App Site Association
        $appleRes = $this->get('/.well-known/apple-app-site-association');
        $appleRes->assertStatus(200)
            ->assertHeader('Content-Type', 'application/json');

        $appleData = $appleRes->json();
        $this->assertArrayHasKey('webcredentials', $appleData);
        $this->assertIsArray($appleData['webcredentials']['apps']);
        $this->assertNotEmpty($appleData['webcredentials']['apps']);
    }

    public function test_passkey_registration_options_requires_authentication(): void
    {
        $this->postJson('/api/v1/auth/passkeys/registration/options')
            ->assertStatus(401);
    }

    public function test_passkey_registration_options_requires_recent_reauthentication(): void
    {
        $user = User::factory()->create([
            'email' => 'passkey_reg_reauth@codebridges.io',
        ]);

        Sanctum::actingAs($user, ['*']);

        $res = $this->postJson('/api/v1/auth/passkeys/registration/options');
        $res->assertStatus(403)
            ->assertJson([
                'status' => 'error',
                'code' => 'REAUTHENTICATION_REQUIRED',
            ]);
    }

    public function test_step_up_reauthenticate_via_password_and_generate_registration_options(): void
    {
        $password = 'SecretPasskey2026!';
        $user = User::factory()->create([
            'email' => 'passkey_options@codebridges.io',
            'password' => Hash::make($password),
        ]);

        Sanctum::actingAs($user, ['*']);

        // 1. Wrong password fails
        $this->postJson('/api/v1/auth/reauthenticate/verify', [
            'purpose' => 'passkey_enrollment',
            'password' => 'WrongPass!',
        ])->assertStatus(422);

        // 2. Correct password grants step-up access
        $reauthRes = $this->postJson('/api/v1/auth/reauthenticate/verify', [
            'purpose' => 'passkey_enrollment',
            'password' => $password,
        ]);
        $reauthRes->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.purpose', 'passkey_enrollment');

        // 3. Now registration options generation succeeds
        $res = $this->postJson('/api/v1/auth/passkeys/registration/options');
        $res->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'data' => [
                    'challenge_id',
                    'options' => [
                        'rp' => ['name', 'id'],
                        'user' => ['id', 'name', 'displayName'],
                        'challenge',
                        'pubKeyCredParams',
                        'authenticatorSelection',
                        'timeout',
                    ],
                ],
            ]);

        $challenge = $res->json('data.options.challenge');
        $this->assertGreaterThanOrEqual(32, strlen(base64_decode(strtr($challenge, '-_', '+/'))));
    }

    public function test_passkey_registration_verify_rejects_missing_or_consumed_challenge(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $this->passkeyService->createReauthGrant($user, 'passkey_enrollment');

        $res = $this->postJson('/api/v1/auth/passkeys/registration/verify', [
            'challenge_id' => 'non_existent_challenge_id',
            'credential' => [
                'id' => 'fake_cred_id',
                'response' => [
                    'clientDataJSON' => base64_encode('{}'),
                    'attestationObject' => base64_encode('{}'),
                ],
            ],
        ]);

        $res->assertStatus(400)
            ->assertJsonPath('code', 'REGISTRATION_FAILED');
    }

    public function test_passkey_registration_verify_rejects_origin_mismatch(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $this->passkeyService->createReauthGrant($user, 'passkey_enrollment');
        $options = $this->passkeyService->generateRegistrationOptions($user);
        $keyData = $this->generateEcKeyPair();

        $credIdRaw = random_bytes(16);
        $attObj = $this->craftAttestationObject($credIdRaw, $keyData['cose_bytes'], true);

        $clientDataJSON = json_encode([
            'type' => 'webauthn.create',
            'challenge' => $options['options']['challenge'],
            'origin' => 'https://evil-hacker-site.com',
        ]);

        $res = $this->postJson('/api/v1/auth/passkeys/registration/verify', [
            'challenge_id' => $options['challenge_id'],
            'credential' => [
                'id' => rtrim(strtr(base64_encode($credIdRaw), '+/', '-_'), '='),
                'response' => [
                    'clientDataJSON' => rtrim(strtr(base64_encode($clientDataJSON), '+/', '-_'), '='),
                    'attestationObject' => rtrim(strtr(base64_encode($attObj), '+/', '-_'), '='),
                ],
            ],
        ]);

        $res->assertStatus(400)
            ->assertJsonPath('code', 'REGISTRATION_FAILED');
    }

    public function test_passkey_registration_verify_requires_user_verification_flag(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $this->passkeyService->createReauthGrant($user, 'passkey_enrollment');
        $options = $this->passkeyService->generateRegistrationOptions($user);
        $keyData = $this->generateEcKeyPair();

        $credIdRaw = random_bytes(16);
        // userVerified = false (UV bit = 0)
        $attObj = $this->craftAttestationObject($credIdRaw, $keyData['cose_bytes'], false);

        $clientDataJSON = json_encode([
            'type' => 'webauthn.create',
            'challenge' => $options['options']['challenge'],
            'origin' => 'http://localhost',
        ]);

        $res = $this->postJson('/api/v1/auth/passkeys/registration/verify', [
            'challenge_id' => $options['challenge_id'],
            'credential' => [
                'id' => rtrim(strtr(base64_encode($credIdRaw), '+/', '-_'), '='),
                'response' => [
                    'clientDataJSON' => rtrim(strtr(base64_encode($clientDataJSON), '+/', '-_'), '='),
                    'attestationObject' => rtrim(strtr(base64_encode($attObj), '+/', '-_'), '='),
                ],
            ],
        ]);

        $res->assertStatus(400)
            ->assertJsonPath('code', 'REGISTRATION_FAILED');
    }

    public function test_passkey_registration_verify_stores_valid_credential_and_sign_count_zero(): void
    {
        $user = User::factory()->create([
            'email' => 'valid_passkey_user@codebridges.io',
        ]);
        Sanctum::actingAs($user, ['*']);

        $this->passkeyService->createReauthGrant($user, 'passkey_enrollment');
        $options = $this->passkeyService->generateRegistrationOptions($user);
        $keyData = $this->generateEcKeyPair();

        $credIdRaw = random_bytes(16);
        $credIdEncoded = rtrim(strtr(base64_encode($credIdRaw), '+/', '-_'), '=');
        $attObj = $this->craftAttestationObject($credIdRaw, $keyData['cose_bytes'], true);

        $clientDataJSON = json_encode([
            'type' => 'webauthn.create',
            'challenge' => $options['options']['challenge'],
            'origin' => 'http://localhost',
        ]);

        $res = $this->postJson('/api/v1/auth/passkeys/registration/verify', [
            'challenge_id' => $options['challenge_id'],
            'name' => 'MacBook Touch ID',
            'credential' => [
                'id' => $credIdEncoded,
                'response' => [
                    'clientDataJSON' => rtrim(strtr(base64_encode($clientDataJSON), '+/', '-_'), '='),
                    'attestationObject' => rtrim(strtr(base64_encode($attObj), '+/', '-_'), '='),
                ],
            ],
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.name', 'MacBook Touch ID')
            ->assertJsonPath('data.credential_id', $credIdEncoded);

        $this->assertDatabaseHas('passkey_credentials', [
            'user_id' => $user->id,
            'credential_id' => $credIdEncoded,
            'name' => 'MacBook Touch ID',
            'sign_count' => 0,
            'revoked_at' => null,
        ]);
    }

    public function test_passkey_registration_verify_rejects_duplicate_credential_id(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $keyData = $this->generateEcKeyPair();
        $credIdRaw = random_bytes(16);
        $credIdEncoded = rtrim(strtr(base64_encode($credIdRaw), '+/', '-_'), '=');

        PasskeyCredential::create([
            'user_id' => $user1->id,
            'credential_id' => $credIdEncoded,
            'user_handle' => (string) $user1->id,
            'public_key' => rtrim(strtr(base64_encode($keyData['cose_bytes']), '+/', '-_'), '='),
            'sign_count' => 0,
            'backup_eligible' => true,
            'backup_state' => false,
            'name' => 'Primary Key',
        ]);

        Sanctum::actingAs($user2, ['*']);
        $this->passkeyService->createReauthGrant($user2, 'passkey_enrollment');
        $options = $this->passkeyService->generateRegistrationOptions($user2);

        $attObj = $this->craftAttestationObject($credIdRaw, $keyData['cose_bytes'], true);
        $clientDataJSON = json_encode([
            'type' => 'webauthn.create',
            'challenge' => $options['options']['challenge'],
            'origin' => 'http://localhost',
        ]);

        $res = $this->postJson('/api/v1/auth/passkeys/registration/verify', [
            'challenge_id' => $options['challenge_id'],
            'credential' => [
                'id' => $credIdEncoded,
                'response' => [
                    'clientDataJSON' => rtrim(strtr(base64_encode($clientDataJSON), '+/', '-_'), '='),
                    'attestationObject' => rtrim(strtr(base64_encode($attObj), '+/', '-_'), '='),
                ],
            ],
        ]);

        $res->assertStatus(400)
            ->assertJsonPath('code', 'REGISTRATION_FAILED');
    }

    public function test_passkey_authentication_options_returns_valid_challenge_and_scoped_credentials(): void
    {
        $user = User::factory()->create([
            'email' => 'scoped_passkey@codebridges.io',
        ]);

        $keyData = $this->generateEcKeyPair();
        $credIdEncoded = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');

        PasskeyCredential::create([
            'user_id' => $user->id,
            'credential_id' => $credIdEncoded,
            'user_handle' => (string) $user->id,
            'public_key' => rtrim(strtr(base64_encode($keyData['cose_bytes']), '+/', '-_'), '='),
            'sign_count' => 0,
            'backup_eligible' => true,
            'backup_state' => false,
            'name' => 'YubiKey 5C',
        ]);

        // 1. Without email: public discoverable options
        $resPublic = $this->postJson('/api/v1/auth/passkeys/authentication/options');
        $resPublic->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.options.allowCredentials', []);

        // 2. With email: scoped allowed credentials list
        $resScoped = $this->postJson('/api/v1/auth/passkeys/authentication/options', [
            'email' => 'scoped_passkey@codebridges.io',
        ]);
        $resScoped->assertStatus(200)
            ->assertJsonPath('status', 'success');

        $allowCreds = $resScoped->json('data.options.allowCredentials');
        $this->assertCount(1, $allowCreds);
        $this->assertEquals($credIdEncoded, $allowCreds[0]['id']);
    }

    public function test_passkey_authentication_options_for_unknown_email_does_not_leak_user_existence(): void
    {
        $res = $this->postJson('/api/v1/auth/passkeys/authentication/options', [
            'email' => 'completely_unknown_user_12345@codebridges.io',
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.options.allowCredentials', []);
    }

    public function test_passkey_authentication_verify_validates_signature_and_issues_session_and_refresh_token(): void
    {
        $user = User::factory()->create([
            'email' => 'passkey_login_user@codebridges.io',
            'is_active' => true,
        ]);

        $keyData = $this->generateEcKeyPair();
        $credIdRaw = random_bytes(16);
        $credIdEncoded = rtrim(strtr(base64_encode($credIdRaw), '+/', '-_'), '=');

        $passkey = PasskeyCredential::create([
            'user_id' => $user->id,
            'credential_id' => $credIdEncoded,
            'user_handle' => (string) $user->id,
            'public_key' => rtrim(strtr(base64_encode($keyData['cose_bytes']), '+/', '-_'), '='),
            'sign_count' => 0,
            'backup_eligible' => true,
            'backup_state' => false,
            'name' => 'FIDO2 Security Key',
        ]);

        // 1. Get authentication options
        $optionsRes = $this->postJson('/api/v1/auth/passkeys/authentication/options', [
            'email' => $user->email,
        ]);
        $challengeId = $optionsRes->json('data.challenge_id');
        $challenge = $optionsRes->json('data.options.challenge');

        // 2. Craft clientDataJSON and authenticatorData
        $clientDataJSON = json_encode([
            'type' => 'webauthn.get',
            'challenge' => $challenge,
            'origin' => 'http://localhost',
        ]);

        $authData = $this->craftAssertionAuthData(counter: 1, userVerified: true);

        // 3. Cryptographically sign authData . sha256(clientDataJSON)
        $clientDataHash = hash('sha256', $clientDataJSON, true);
        $signedData = $authData . $clientDataHash;
        openssl_sign($signedData, $signature, $keyData['private_key'], OPENSSL_ALGO_SHA256);

        // 4. Verify assertion
        $verifyRes = $this->postJson('/api/v1/auth/passkeys/authentication/verify', [
            'challenge_id' => $challengeId,
            'credential' => [
                'id' => $credIdEncoded,
                'response' => [
                    'clientDataJSON' => rtrim(strtr(base64_encode($clientDataJSON), '+/', '-_'), '='),
                    'authenticatorData' => rtrim(strtr(base64_encode($authData), '+/', '-_'), '='),
                    'signature' => rtrim(strtr(base64_encode($signature), '+/', '-_'), '='),
                ],
            ],
        ]);

        $verifyRes->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure([
                'status',
                'access_token',
                'refresh_token',
                'session_uuid',
                'user',
            ]);

        $this->assertStringStartsWith('rt_', $verifyRes->json('refresh_token'));

        // Verify JWT has passkey AMR claim
        $accessToken = $verifyRes->json('access_token');
        $jwtPayload = $this->jwtService->verify($accessToken);
        $this->assertNotNull($jwtPayload);
        $this->assertContains('passkey', $jwtPayload['amr'] ?? []);
        $this->assertContains('user_verified', $jwtPayload['amr'] ?? []);

        // Verify passkey sign_count updated
        $passkey->refresh();
        $this->assertEquals(1, $passkey->sign_count);
        $this->assertNotNull($passkey->last_used_at);
    }

    public function test_passkey_authentication_verify_rejects_tampered_signature(): void
    {
        $user = User::factory()->create([
            'email' => 'tampered_sig@codebridges.io',
            'is_active' => true,
        ]);

        $keyData = $this->generateEcKeyPair();
        $credIdEncoded = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');

        PasskeyCredential::create([
            'user_id' => $user->id,
            'credential_id' => $credIdEncoded,
            'user_handle' => (string) $user->id,
            'public_key' => rtrim(strtr(base64_encode($keyData['cose_bytes']), '+/', '-_'), '='),
            'sign_count' => 0,
            'backup_eligible' => true,
            'backup_state' => false,
            'name' => 'FIDO2 Security Key',
        ]);

        $optionsRes = $this->postJson('/api/v1/auth/passkeys/authentication/options', [
            'email' => $user->email,
        ]);
        $challengeId = $optionsRes->json('data.challenge_id');
        $challenge = $optionsRes->json('data.options.challenge');

        $clientDataJSON = json_encode([
            'type' => 'webauthn.get',
            'challenge' => $challenge,
            'origin' => 'http://localhost',
        ]);

        $authData = $this->craftAssertionAuthData(counter: 1, userVerified: true);

        // Valid signature generated with a DIFFERENT key pair (signature forgery attempt)
        $bogusKey = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $clientDataHash = hash('sha256', $clientDataJSON, true);
        openssl_sign($authData . $clientDataHash, $bogusSignature, $bogusKey, OPENSSL_ALGO_SHA256);

        $verifyRes = $this->postJson('/api/v1/auth/passkeys/authentication/verify', [
            'challenge_id' => $challengeId,
            'credential' => [
                'id' => $credIdEncoded,
                'response' => [
                    'clientDataJSON' => rtrim(strtr(base64_encode($clientDataJSON), '+/', '-_'), '='),
                    'authenticatorData' => rtrim(strtr(base64_encode($authData), '+/', '-_'), '='),
                    'signature' => rtrim(strtr(base64_encode($bogusSignature), '+/', '-_'), '='),
                ],
            ],
        ]);

        $verifyRes->assertStatus(401)
            ->assertJsonPath('code', 'AUTHENTICATION_FAILED');
    }

    public function test_passkey_authentication_verify_rejects_unverified_user_uv_false(): void
    {
        $user = User::factory()->create([
            'email' => 'unverified_uv@codebridges.io',
            'is_active' => true,
        ]);

        $keyData = $this->generateEcKeyPair();
        $credIdEncoded = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');

        PasskeyCredential::create([
            'user_id' => $user->id,
            'credential_id' => $credIdEncoded,
            'user_handle' => (string) $user->id,
            'public_key' => rtrim(strtr(base64_encode($keyData['cose_bytes']), '+/', '-_'), '='),
            'sign_count' => 0,
            'backup_eligible' => true,
            'backup_state' => false,
            'name' => 'FIDO2 Security Key',
        ]);

        $optionsRes = $this->postJson('/api/v1/auth/passkeys/authentication/options', [
            'email' => $user->email,
        ]);
        $challengeId = $optionsRes->json('data.challenge_id');
        $challenge = $optionsRes->json('data.options.challenge');

        $clientDataJSON = json_encode([
            'type' => 'webauthn.get',
            'challenge' => $challenge,
            'origin' => 'http://localhost',
        ]);

        // userVerified = false (UV bit = 0)
        $authData = $this->craftAssertionAuthData(counter: 1, userVerified: false);
        $clientDataHash = hash('sha256', $clientDataJSON, true);
        openssl_sign($authData . $clientDataHash, $signature, $keyData['private_key'], OPENSSL_ALGO_SHA256);

        $verifyRes = $this->postJson('/api/v1/auth/passkeys/authentication/verify', [
            'challenge_id' => $challengeId,
            'credential' => [
                'id' => $credIdEncoded,
                'response' => [
                    'clientDataJSON' => rtrim(strtr(base64_encode($clientDataJSON), '+/', '-_'), '='),
                    'authenticatorData' => rtrim(strtr(base64_encode($authData), '+/', '-_'), '='),
                    'signature' => rtrim(strtr(base64_encode($signature), '+/', '-_'), '='),
                ],
            ],
        ]);

        $verifyRes->assertStatus(401)
            ->assertJsonPath('code', 'AUTHENTICATION_FAILED');
    }

    public function test_passkey_authentication_verify_detects_cloned_authenticator(): void
    {
        $user = User::factory()->create([
            'email' => 'cloned_auth@codebridges.io',
            'is_active' => true,
        ]);

        $keyData = $this->generateEcKeyPair();
        $credIdEncoded = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');

        // Existing credential has already reached sign_count = 10
        $passkey = PasskeyCredential::create([
            'user_id' => $user->id,
            'credential_id' => $credIdEncoded,
            'user_handle' => (string) $user->id,
            'public_key' => rtrim(strtr(base64_encode($keyData['cose_bytes']), '+/', '-_'), '='),
            'sign_count' => 10,
            'backup_eligible' => true,
            'backup_state' => false,
            'name' => 'Cloned Key Test',
        ]);

        $optionsRes = $this->postJson('/api/v1/auth/passkeys/authentication/options', [
            'email' => $user->email,
        ]);
        $challengeId = $optionsRes->json('data.challenge_id');
        $challenge = $optionsRes->json('data.options.challenge');

        $clientDataJSON = json_encode([
            'type' => 'webauthn.get',
            'challenge' => $challenge,
            'origin' => 'http://localhost',
        ]);

        // Incoming assertion presents counter = 5 (replayed / cloned counter <= 10)
        $authData = $this->craftAssertionAuthData(counter: 5, userVerified: true);
        $clientDataHash = hash('sha256', $clientDataJSON, true);
        openssl_sign($authData . $clientDataHash, $signature, $keyData['private_key'], OPENSSL_ALGO_SHA256);

        $verifyRes = $this->postJson('/api/v1/auth/passkeys/authentication/verify', [
            'challenge_id' => $challengeId,
            'credential' => [
                'id' => $credIdEncoded,
                'response' => [
                    'clientDataJSON' => rtrim(strtr(base64_encode($clientDataJSON), '+/', '-_'), '='),
                    'authenticatorData' => rtrim(strtr(base64_encode($authData), '+/', '-_'), '='),
                    'signature' => rtrim(strtr(base64_encode($signature), '+/', '-_'), '='),
                ],
            ],
        ]);

        $verifyRes->assertStatus(401)
            ->assertJsonPath('code', 'AUTHENTICATION_FAILED');

        // Passkey was immediately revoked
        $passkey->refresh();
        $this->assertNotNull($passkey->revoked_at);
    }

    public function test_passkey_authentication_verify_rejects_revoked_passkey(): void
    {
        $user = User::factory()->create([
            'email' => 'revoked_passkey@codebridges.io',
            'is_active' => true,
        ]);

        $keyData = $this->generateEcKeyPair();
        $credIdEncoded = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');

        PasskeyCredential::create([
            'user_id' => $user->id,
            'credential_id' => $credIdEncoded,
            'user_handle' => (string) $user->id,
            'public_key' => rtrim(strtr(base64_encode($keyData['cose_bytes']), '+/', '-_'), '='),
            'sign_count' => 0,
            'backup_eligible' => true,
            'backup_state' => false,
            'name' => 'Revoked Key',
            'revoked_at' => now(),
        ]);

        $optionsRes = $this->postJson('/api/v1/auth/passkeys/authentication/options', [
            'email' => $user->email,
        ]);

        $verifyRes = $this->postJson('/api/v1/auth/passkeys/authentication/verify', [
            'challenge_id' => $optionsRes->json('data.challenge_id'),
            'credential' => [
                'id' => $credIdEncoded,
                'response' => [
                    'clientDataJSON' => base64_encode('{}'),
                    'authenticatorData' => base64_encode('{}'),
                    'signature' => base64_encode('{}'),
                ],
            ],
        ]);

        $verifyRes->assertStatus(401)
            ->assertJsonPath('code', 'AUTHENTICATION_FAILED');
    }

    public function test_passkey_list_shows_only_metadata_for_owner(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $keyData = $this->generateEcKeyPair();
        PasskeyCredential::create([
            'user_id' => $user->id,
            'credential_id' => 'my_first_cred_id',
            'user_handle' => (string) $user->id,
            'public_key' => rtrim(strtr(base64_encode($keyData['cose_bytes']), '+/', '-_'), '='),
            'sign_count' => 2,
            'backup_eligible' => true,
            'backup_state' => true,
            'name' => 'Office Security Key',
        ]);

        $res = $this->getJson('/api/v1/auth/passkeys');
        $res->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonCount(1, 'data')
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'credential_id',
                        'name',
                        'backup_eligible',
                        'backup_state',
                        'created_at',
                    ],
                ],
            ])
            ->assertJsonMissing(['public_key', 'private_key', 'user_handle']);
    }

    public function test_passkey_rename_updates_name(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $passkey = PasskeyCredential::create([
            'user_id' => $user->id,
            'credential_id' => 'rename_cred_id',
            'user_handle' => (string) $user->id,
            'public_key' => 'fake_pubkey',
            'sign_count' => 0,
            'backup_eligible' => true,
            'backup_state' => false,
            'name' => 'Old Name',
        ]);

        $res = $this->patchJson("/api/v1/auth/passkeys/{$passkey->id}", [
            'name' => 'Renamed MacBook Passkey',
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.name', 'Renamed MacBook Passkey');

        $passkey->refresh();
        $this->assertEquals('Renamed MacBook Passkey', $passkey->name);
    }

    public function test_passkey_delete_requires_reauth_and_prevents_deleting_last_login_method(): void
    {
        // User with NO password
        $user = User::factory()->create();
        \Illuminate\Support\Facades\DB::table('users')->where('id', $user->id)->update(['password' => '!nopassword!']);
        $user->refresh();
        Sanctum::actingAs($user, ['*']);

        $passkey = PasskeyCredential::create([
            'user_id' => $user->id,
            'credential_id' => 'sole_cred_id',
            'user_handle' => (string) $user->id,
            'public_key' => 'fake_pubkey',
            'sign_count' => 0,
            'backup_eligible' => true,
            'backup_state' => false,
            'name' => 'Sole Login Passkey',
        ]);

        // 1. Without reauth -> 403
        $this->deleteJson("/api/v1/auth/passkeys/{$passkey->id}")
            ->assertStatus(403)
            ->assertJsonPath('code', 'REAUTHENTICATION_REQUIRED');

        // Grant reauth
        $this->passkeyService->createReauthGrant($user, 'credential_management');

        // 2. User has no password and this is the only passkey -> 422
        $this->deleteJson("/api/v1/auth/passkeys/{$passkey->id}")
            ->assertStatus(422)
            ->assertJsonPath('code', 'LAST_CREDENTIAL_CANNOT_BE_REMOVED');

        // 3. Set a password, re-grant reauth, deletion succeeds
        $user->password = Hash::make('NewPassword123!');
        $user->save();

        $this->passkeyService->createReauthGrant($user, 'credential_management');

        $delRes = $this->deleteJson("/api/v1/auth/passkeys/{$passkey->id}");
        $delRes->assertStatus(200)
            ->assertJsonPath('status', 'success');

        $passkey->refresh();
        $this->assertNotNull($passkey->revoked_at);
    }

    public function test_reauthentication_with_passkey_issues_grant_token(): void
    {
        $user = User::factory()->create([
            'email' => 'reauth_passkey@codebridges.io',
            'is_active' => true,
        ]);
        Sanctum::actingAs($user, ['*']);

        $keyData = $this->generateEcKeyPair();
        $credIdEncoded = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');

        PasskeyCredential::create([
            'user_id' => $user->id,
            'credential_id' => $credIdEncoded,
            'user_handle' => (string) $user->id,
            'public_key' => rtrim(strtr(base64_encode($keyData['cose_bytes']), '+/', '-_'), '='),
            'sign_count' => 0,
            'backup_eligible' => true,
            'backup_state' => false,
            'name' => 'FIDO2 Key',
        ]);

        // 1. Request reauth options
        $optionsRes = $this->postJson('/api/v1/auth/reauthenticate/options', [
            'purpose' => 'change_payment_details',
        ]);
        $optionsRes->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.purpose', 'change_payment_details')
            ->assertJsonStructure(['data' => ['challenge_id', 'passkey_options']]);

        $challengeId = $optionsRes->json('data.challenge_id');
        $challenge = $optionsRes->json('data.passkey_options.challenge');

        // 2. Craft assertion
        $clientDataJSON = json_encode([
            'type' => 'webauthn.get',
            'challenge' => $challenge,
            'origin' => 'http://localhost',
        ]);
        $authData = $this->craftAssertionAuthData(counter: 1, userVerified: true);
        $clientDataHash = hash('sha256', $clientDataJSON, true);
        openssl_sign($authData . $clientDataHash, $signature, $keyData['private_key'], OPENSSL_ALGO_SHA256);

        // 3. Verify reauthentication assertion
        $verifyRes = $this->postJson('/api/v1/auth/reauthenticate/verify', [
            'purpose' => 'change_payment_details',
            'challenge_id' => $challengeId,
            'credential' => [
                'id' => $credIdEncoded,
                'response' => [
                    'clientDataJSON' => rtrim(strtr(base64_encode($clientDataJSON), '+/', '-_'), '='),
                    'authenticatorData' => rtrim(strtr(base64_encode($authData), '+/', '-_'), '='),
                    'signature' => rtrim(strtr(base64_encode($signature), '+/', '-_'), '='),
                ],
            ],
        ]);

        $verifyRes->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.purpose', 'change_payment_details')
            ->assertJsonStructure(['data' => ['grant_token', 'expires_in']]);

        $this->assertTrue($this->passkeyService->hasRecentReauth($user, 'change_payment_details'));
    }
}
