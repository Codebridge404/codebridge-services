<?php

namespace Tests\Feature\Security;

use App\Models\Auth\User;
use App\Services\PasskeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\PasskeyTestHelpers;
use Tests\TestCase;

class ReviewDefectsRegressionTest extends TestCase
{
    use PasskeyTestHelpers, RefreshDatabase;

    protected PasskeyService $passkeyService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->passkeyService = app(PasskeyService::class);
    }

    /**
     * Issue 2: Reauthentication grant cannot move between authenticated sessions via client header.
     */
    public function test_review_grant_cannot_move_between_authenticated_sessions_via_header(): void
    {
        $user = User::factory()->create();
        $sessionA = (object) ['id' => 1001];
        $sessionB = (object) ['id' => 1002];

        $requestA = Request::create('/');
        $requestA->headers->set('X-Session-Id', 'caller-controlled');
        $user->withAccessToken($sessionA);
        $sidA = $this->passkeyService->resolveSessionId($requestA, $user);
        $grant = $this->passkeyService->createReauthGrant($user, $sidA, 'passkey_enrollment');

        $requestB = Request::create('/');
        $requestB->headers->set('X-Session-Id', 'caller-controlled');
        $user->withAccessToken($sessionB);
        $sidB = $this->passkeyService->resolveSessionId($requestB, $user);

        $this->assertFalse(
            $this->passkeyService->consumeReauthGrantToken($user, $sidB, $grant, 'passkey_enrollment'),
            'A grant from token 1001 must not be usable under token 1002'
        );
    }

    /**
     * Issue 3: Passkey registration strictly validates RP ID hash against expected host.
     */
    public function test_review_registration_rejects_wrong_rp_hash(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $options = $this->passkeyService->generateRegistrationOptions($user);
        $key = $this->generateEcKeyPair();
        $id = random_bytes(16);
        $attestation = $this->craftAttestationObject($id, $key['cose_bytes']);
        $attestation = str_replace(hash('sha256', 'localhost', true), hash('sha256', 'unrelated.example', true), $attestation);

        $client = json_encode([
            'type' => 'webauthn.create',
            'challenge' => $options['options']['challenge'],
            'origin' => 'http://localhost',
        ]);

        $res = $this->postJson('/api/v1/auth/passkeys/registration/verify', [
            'challenge_id' => $options['challenge_id'],
            'credential' => [
                'id' => rtrim(strtr(base64_encode($id), '+/', '-_'), '='),
                'response' => [
                    'clientDataJSON' => base64_encode($client),
                    'attestationObject' => base64_encode($attestation),
                ],
            ],
        ]);

        $this->assertGreaterThanOrEqual(400, $res->status(), 'Attestation for unrelated.example must not register on localhost');
    }

    /**
     * Issue 4: Legacy tokens match between direct relationship and eager loading.
     */
    public function test_review_legacy_token_eager_loading_matches_direct_relation(): void
    {
        $user = User::factory()->create();
        DB::table('personal_access_tokens')->insert([
            'tokenable_id' => $user->id,
            'tokenable_type' => 'App\\Models\\User',
            'name' => 'legacy',
            'token' => hash('sha256', 'review-only'),
            'abilities' => '["*"]',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(1, $user->tokens()->count());

        $loaded = User::with('tokens')->findOrFail($user->id);
        $this->assertCount(1, $loaded->tokens, 'Eager loaded legacy tokens must match direct relation');

        // Verify withCount includes legacy tokens
        $counted = User::withCount('tokens')->findOrFail($user->id);
        $this->assertSame(1, (int) $counted->tokens_count, 'withCount must include supported legacy token aliases');
    }

    /**
     * Issue 2 (Recheck): Passkey registration strictly validates configured RP ID, rejecting mismatched origin hosts.
     */
    public function test_recheck_registration_uses_configured_rp_not_origin_host(): void
    {
        config([
            'webauthn.rp.id' => 'example.test',
            'webauthn.allowed_origins' => ['https://login.example.test'],
        ]);

        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $options = $this->passkeyService->generateRegistrationOptions($user);
        $key = $this->generateEcKeyPair();
        $id = random_bytes(16);
        $attestation = $this->craftAttestationObject($id, $key['cose_bytes']);
        $attestation = str_replace(hash('sha256', 'localhost', true), hash('sha256', 'login.example.test', true), $attestation);

        $client = json_encode([
            'type' => 'webauthn.create',
            'challenge' => $options['options']['challenge'],
            'origin' => 'https://login.example.test',
        ]);

        $res = $this->withHeader('Origin', 'https://login.example.test')->postJson('/api/v1/auth/passkeys/registration/verify', [
            'challenge_id' => $options['challenge_id'],
            'credential' => [
                'id' => rtrim(strtr(base64_encode($id), '+/', '-_'), '='),
                'response' => [
                    'clientDataJSON' => base64_encode($client),
                    'attestationObject' => base64_encode($attestation),
                ],
            ],
        ]);

        $this->assertGreaterThanOrEqual(400, $res->status(), 'RP hash must match requested RP example.test, not merely origin host login.example.test');
    }
}
