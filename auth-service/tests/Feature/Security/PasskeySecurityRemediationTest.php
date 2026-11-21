<?php

namespace Tests\Feature\Security;

use App\Models\Auth\PasskeyCredential;
use App\Models\Auth\User;
use App\Models\Auth\UserSession;
use App\Models\Security\ApiClient;
use App\Services\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\PasskeyTestHelpers;
use Tests\TestCase;

class PasskeySecurityRemediationTest extends TestCase
{
    use PasskeyTestHelpers, RefreshDatabase;

    private const PASSWORD = 'SecurityRegressionPassword123!';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false, 'webauthn.rp.id' => 'localhost', 'webauthn.allowed_origins' => ['http://localhost']]);
    }

    private function b64(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function user(): User
    {
        return User::factory()->create(['is_active' => true, 'password' => self::PASSWORD]);
    }

    private function sessionToken(User $user, string $name, string $type = 'sanctum'): string
    {
        if ($type === 'sanctum') {
            return $user->createToken($name)->plainTextToken;
        }

        $session = UserSession::create([
            'user_id' => $user->id,
            'api_client_id' => ApiClient::firstOrFail()->id,
            'device_name' => $name,
            'permissions' => [],
            'expires_at' => now()->addDay(),
        ]);

        return app(JwtService::class)->generateTokenForUser($user, ['sid' => $session->uuid]);
    }

    private function useToken(string $token): void
    {
        // Laravel's test application persists guards between simulated HTTP requests.
        $this->app['auth']->forgetGuards();
        $this->withToken($token);
    }

    private function credential(User $user): array
    {
        $key = $this->generateEcKeyPair();
        $id = $this->b64(random_bytes(16));
        $credential = PasskeyCredential::create([
            'user_id' => $user->id,
            'credential_id' => $id,
            'user_handle' => (string) ($user->uuid ?? $user->id),
            'public_key' => $this->b64($key['cose_bytes']),
            'sign_count' => 0,
            'name' => 'Regression passkey',
        ]);

        return [$key, $credential];
    }

    private function assertion(array $options, array $key, PasskeyCredential $credential): array
    {
        $client = json_encode([
            'type' => 'webauthn.get',
            'challenge' => $options['options']['challenge'],
            'origin' => 'http://localhost',
        ]);
        $auth = $this->craftAssertionAuthData();
        openssl_sign($auth.hash('sha256', $client, true), $signature, $key['private_key'], OPENSSL_ALGO_SHA256);

        return [
            'challenge_id' => $options['challenge_id'],
            'credential' => [
                'id' => $credential->credential_id,
                'response' => [
                    'clientDataJSON' => $this->b64($client),
                    'authenticatorData' => $this->b64($auth),
                    'signature' => $this->b64($signature),
                    'userHandle' => $this->b64($credential->user_handle),
                ],
            ],
        ];
    }

    public function test_identified_login_rejects_another_accounts_valid_credential(): void
    {
        $alice = $this->user();
        $bob = $this->user();
        $this->credential($alice);
        [$key, $credential] = $this->credential($bob);
        $options = $this->postJson('/api/v1/auth/passkeys/authentication/options', ['email' => $alice->email])
            ->assertOk()->json('data');
        $this->assertNotContains($credential->credential_id, array_column($options['options']['allowCredentials'], 'id'));

        $this->postJson('/api/v1/auth/passkeys/authentication/verify', $this->assertion($options, $key, $credential))
            ->assertUnauthorized()->assertJsonPath('code', 'AUTHENTICATION_FAILED')->assertJsonMissingPath('access_token');
        $this->assertDatabaseCount('user_sessions', 0);
        $this->assertDatabaseCount('refresh_tokens', 0);
        $this->assertSame(0, $credential->fresh()->sign_count);
    }

    public function test_unknown_email_does_not_fall_back_to_discoverable_login(): void
    {
        [$key, $credential] = $this->credential($this->user());
        $options = $this->postJson('/api/v1/auth/passkeys/authentication/options', ['email' => 'missing@example.test'])
            ->assertOk()->json('data');
        $this->assertSame([], $options['options']['allowCredentials']);

        $this->postJson('/api/v1/auth/passkeys/authentication/verify', $this->assertion($options, $key, $credential))
            ->assertUnauthorized()->assertJsonMissingPath('access_token');
        $this->assertDatabaseCount('user_sessions', 0);
    }

    public function test_identified_login_rejects_a_credential_added_after_options_were_issued(): void
    {
        $user = $this->user();
        $this->credential($user);
        $options = $this->postJson('/api/v1/auth/passkeys/authentication/options', ['email' => $user->email])
            ->assertOk()->json('data');
        [$key, $credential] = $this->credential($user);

        $this->postJson('/api/v1/auth/passkeys/authentication/verify', $this->assertion($options, $key, $credential))
            ->assertUnauthorized()->assertJsonMissingPath('access_token');
    }

    public static function loginHandles(): array
    {
        return [
            'identified valid handle' => [true, 'valid', false, 200],
            'discoverable valid handle' => [false, 'valid', false, 200],
            'identified omitted handle' => [true, 'omitted', false, 200],
            'identified null handle' => [true, 'null', false, 200],
            'identified mismatched handle' => [true, 'wrong', false, 401],
            'discoverable mismatched handle' => [false, 'wrong', false, 401],
            'identified malformed handle' => [true, 'malformed', false, 401],
            'legacy flat payload mismatched handle' => [true, 'wrong', true, 401],
        ];
    }

    #[DataProvider('loginHandles')]
    public function test_login_validates_supplied_user_handles(bool $identified, string $handle, bool $flat, int $status): void
    {
        $user = $this->user();
        [$key, $credential] = $this->credential($user);
        $options = $this->postJson('/api/v1/auth/passkeys/authentication/options', $identified ? ['email' => $user->email] : [])
            ->assertOk()->json('data');
        $payload = $this->assertion($options, $key, $credential);
        if ($handle === 'omitted') {
            unset($payload['credential']['response']['userHandle']);
        } elseif ($handle !== 'valid') {
            $payload['credential']['response']['userHandle'] = match ($handle) {
                'wrong' => $this->b64('another-account'),
                'malformed' => '%%%invalid-base64%%%',
                'null' => null,
            };
        }
        if ($flat) {
            $payload = ['challenge_id' => $payload['challenge_id']] + $payload['credential'];
        }

        $response = $this->postJson('/api/v1/auth/passkeys/authentication/verify', $payload)->assertStatus($status);
        if ($status === 200) {
            $claims = app(JwtService::class)->verify($response->json('access_token'));
            $this->assertSame((string) $user->id, $claims['sub']);
        } else {
            $response->assertJsonMissingPath('access_token');
            $this->assertDatabaseCount('user_sessions', 0);
            $this->assertSame(0, $credential->fresh()->sign_count);
        }
    }

    public static function enrollmentSessions(): array
    {
        $cases = [];
        foreach (['sanctum', 'jwt'] as $type) {
            foreach (['same', 'different', 'other_user', 'expired', 'missing_binding'] as $scenario) {
                $cases[$type.' '.$scenario] = [$type, $scenario];
            }
        }

        return $cases;
    }

    #[DataProvider('enrollmentSessions')]
    public function test_enrollment_requires_its_original_account_and_session(string $type, string $scenario): void
    {
        $user = $this->user();
        $tokenA = $this->sessionToken($user, 'Enrollment A', $type);
        $tokenB = $this->sessionToken($scenario === 'other_user' ? $this->user() : $user, 'Enrollment B', $type);
        $this->useToken($tokenA);
        $grant = $this->postJson('/api/v1/auth/reauthenticate/verify', [
            'password' => self::PASSWORD, 'purpose' => 'passkey_enrollment',
        ])->assertOk()->json('data.grant_token');
        $options = $this->postJson('/api/v1/auth/passkeys/registration/options', ['grant_token' => $grant])
            ->assertOk()->json('data');
        $cacheKey = 'auth:passkey_reg_challenge:'.$options['challenge_id'];
        $saved = Cache::get($cacheKey);
        $this->assertStringStartsWith($type === 'sanctum' ? 'pat:' : 'sid:', $saved['session_id']);
        $key = $this->generateEcKeyPair();
        $id = random_bytes(16);
        $client = json_encode(['type' => 'webauthn.create', 'challenge' => $options['options']['challenge'], 'origin' => 'http://localhost']);
        $payload = [
            'challenge_id' => $options['challenge_id'],
            'credential' => ['id' => $this->b64($id), 'response' => [
                'clientDataJSON' => $this->b64($client),
                'attestationObject' => $this->b64($this->craftAttestationObject($id, $key['cose_bytes'])),
            ]],
        ];
        if ($scenario === 'expired') {
            $this->travel(6)->minutes();
        } elseif ($scenario === 'missing_binding') {
            unset($saved['session_id']);
            Cache::put($cacheKey, $saved, 300);
        }
        $this->useToken(in_array($scenario, ['different', 'other_user'], true) ? $tokenB : $tokenA);
        // A caller-controlled session header must not override the authenticated token/session.
        $this->withHeader('X-Session-Id', $saved['session_id'] ?? 'spoofed');
        $response = $this->postJson('/api/v1/auth/passkeys/registration/verify', $payload);
        if ($scenario === 'same') {
            $response->assertCreated();
            $this->assertDatabaseHas('passkey_credentials', ['credential_id' => $this->b64($id), 'user_id' => $user->id]);
            $this->postJson('/api/v1/auth/passkeys/registration/verify', $payload)->assertStatus(400);
            $this->assertDatabaseCount('passkey_credentials', 1);
        } else {
            $response->assertStatus(400)->assertJsonPath('code', 'REGISTRATION_FAILED');
            $this->assertDatabaseCount('passkey_credentials', 0);
        }
        $this->assertFalse(Cache::has($cacheKey));
    }

    public function test_reauthentication_blocks_guesses_across_sessions_ips_and_spoofed_identifiers(): void
    {
        $user = $this->user();
        $tokenA = $this->sessionToken($user, 'Guess A');
        $tokenB = $this->sessionToken($user, 'Guess B');
        $this->useToken($tokenA);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.($attempt + 1)])
                ->postJson('/api/v1/auth/reauthenticate/verify', [
                    'password' => 'WrongPassword123!', 'email' => 'spoof'.$attempt.'@example.test',
                ])->assertStatus(422)->assertJsonPath('code', 'INVALID_CREDENTIALS');
        }
        $this->useToken($tokenB);
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.99'])
            ->postJson('/api/v1/auth/reauthenticate/verify', ['password' => self::PASSWORD])
            ->assertStatus(429)->assertHeader('Retry-After')->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('code', 'REAUTHENTICATION_RATE_LIMITED')->assertJsonMissingPath('data.grant_token');
        $this->travel(16)->minutes();
        $this->postJson('/api/v1/auth/reauthenticate/verify', ['password' => self::PASSWORD])
            ->assertOk()->assertJsonStructure(['data' => ['grant_token']]);
    }

    public function test_reauthentication_options_cannot_clear_verification_attempts(): void
    {
        $this->useToken($this->sessionToken($this->user(), 'Options reset'));
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/auth/reauthenticate/verify', ['password' => 'WrongPassword123!'])->assertStatus(422);
            $this->postJson('/api/v1/auth/reauthenticate/options')->assertOk();
        }
        $this->postJson('/api/v1/auth/reauthenticate/verify', ['password' => self::PASSWORD])->assertStatus(429);
    }

    public function test_failed_passkey_and_password_reauthentication_share_the_account_limit(): void
    {
        $this->useToken($this->sessionToken($this->user(), 'Shared methods'));
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $payload = $attempt % 2 === 0 ? ['password' => 'WrongPassword123!'] : ['challenge_id' => 'invalid'];
            $this->postJson('/api/v1/auth/reauthenticate/verify', $payload)->assertStatus($attempt % 2 === 0 ? 422 : 401);
        }
        $this->postJson('/api/v1/auth/reauthenticate/verify', ['password' => self::PASSWORD])->assertStatus(429);
    }

    public function test_ip_limit_applies_across_different_authenticated_accounts(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.42']);
        for ($attempt = 0; $attempt < 31; $attempt++) {
            $this->useToken($this->sessionToken($this->user(), 'IP limit '.$attempt));
            $response = $this->postJson('/api/v1/auth/reauthenticate/verify', ['password' => 'WrongPassword123!']);
            if ($attempt < 30) {
                $response->assertStatus(422);
            } else {
                $response->assertStatus(429)->assertHeader('Retry-After')->assertJsonPath('code', 'REAUTHENTICATION_RATE_LIMITED');
            }
        }
    }
}
