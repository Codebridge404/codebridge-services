<?php

namespace Tests\Feature;

use App\Models\ApiClient;
use App\Models\RefreshToken;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Models\UserSession;
use App\Services\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserSessionRotationFlowTest extends TestCase
{
    use RefreshDatabase;

    protected ApiClient $posClient;
    protected User $user;
    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->posClient = ApiClient::create([
            'name' => 'CodeBridges Flutter POS',
            'client_key' => 'client_pos_flutter_cb2026',
            'client_type' => 'public',
            'is_active' => true,
        ]);

        $this->user = User::factory()->create([
            'email' => 'cashier@sunnycafe.kh',
            'password' => Hash::make('Password123!'),
        ]);

        $this->tenant = Tenant::create([
            'name' => 'Sunny Cafe',
            'slug' => 'sunny-cafe-' . uniqid(),
            'status' => 'active',
            'owner_user_id' => $this->user->id,
        ]);

        TenantUser::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'status' => 'active',
        ]);
    }

    /**
     * Test full login, session family creation, rotation, and access verification.
     */
    public function test_full_login_session_creation_and_token_rotation(): void
    {
        $loginRes = $this->withHeader('X-Client-Key', 'client_pos_flutter_cb2026')
            ->postJson('/api/v1/auth/login', [
                'email' => 'cashier@sunnycafe.kh',
                'password' => 'Password123!',
            ])
            ->assertOk();

        $accessToken = $loginRes->json('access_token');
        $refreshToken = $loginRes->json('refresh_token');
        $sessionUuid = $loginRes->json('session_uuid');

        $this->assertNotEmpty($accessToken);
        $this->assertNotEmpty($refreshToken);
        $this->assertStringStartsWith('rt_', $refreshToken);
        $this->assertNotEmpty($sessionUuid);

        // Verify JWT sid matches user_sessions.uuid
        $jwtService = app(JwtService::class);
        $claims = $jwtService->verify($accessToken);
        $this->assertEquals($sessionUuid, $claims['sid']);

        // Verify UserSession in DB
        $userSession = UserSession::where('uuid', $sessionUuid)->first();
        $this->assertNotNull($userSession);
        $this->assertEquals($this->user->id, $userSession->user_id);
        $this->assertEquals($this->posClient->id, $userSession->api_client_id);
        $this->assertTrue($userSession->isActive());

        // Verify Root RefreshToken in DB
        $parsed = RefreshToken::parseCredential($refreshToken);
        $this->assertNotNull($parsed);
        $rootToken = RefreshToken::where('token_id', $parsed['token_id'])->first();
        $this->assertNotNull($rootToken);
        $this->assertEquals($userSession->id, $rootToken->session_id);
        $this->assertNull($rootToken->parent_token_id);
        $this->assertNull($rootToken->consumed_at);

        // Access protected route
        $this->withHeader('Authorization', 'Bearer ' . $accessToken)
            ->getJson('/api/v1/me')
            ->assertOk();

        // Perform rotation
        $refreshRes = $this->postJson('/api/v1/auth/refresh', [
            'refresh_token' => $refreshToken,
        ])->assertOk();

        $newAccessToken = $refreshRes->json('access_token');
        $newRefreshToken = $refreshRes->json('refresh_token');

        $this->assertNotEquals($accessToken, $newAccessToken);
        $this->assertNotEquals($refreshToken, $newRefreshToken);
        $this->assertStringStartsWith('rt_', $newRefreshToken);

        // Verify root token is consumed
        $rootToken->refresh();
        $this->assertNotNull($rootToken->consumed_at);

        // Verify successor token
        $parsedSuccessor = RefreshToken::parseCredential($newRefreshToken);
        $successorToken = RefreshToken::where('token_id', $parsedSuccessor['token_id'])->first();
        $this->assertNotNull($successorToken);
        $this->assertEquals($rootToken->id, $successorToken->parent_token_id);
        $this->assertEquals($userSession->id, $successorToken->session_id);
        $this->assertNull($successorToken->consumed_at);

        // Verify new access token works
        $this->withHeader('Authorization', 'Bearer ' . $newAccessToken)
            ->getJson('/api/v1/me')
            ->assertOk();
    }

    /**
     * Test replay attack detection on consumed rotating token.
     */
    public function test_replay_attack_on_consumed_token_revokes_session_family(): void
    {
        $loginRes = $this->postJson('/api/v1/auth/login', [
            'email' => 'cashier@sunnycafe.kh',
            'password' => 'Password123!',
        ])->assertOk();

        $rootToken = $loginRes->json('refresh_token');
        $sessionUuid = $loginRes->json('session_uuid');

        // Legitimate rotation
        $firstRefresh = $this->postJson('/api/v1/auth/refresh', [
            'refresh_token' => $rootToken,
        ])->assertOk();

        $successorToken = $firstRefresh->json('refresh_token');

        // Attacker replays consumed root token
        $replayRes = $this->postJson('/api/v1/auth/refresh', [
            'refresh_token' => $rootToken,
        ])->assertUnauthorized();

        $this->assertStringContainsString('consumed', $replayRes->json('message'));

        // Verify session family is revoked
        $userSession = UserSession::where('uuid', $sessionUuid)->first();
        $this->assertNotNull($userSession->revoked_at);
        $this->assertEquals('replay_attack_detected', $userSession->revocation_reason);

        // Both tokens now fail
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $successorToken])
            ->assertUnauthorized();
    }

    /**
     * Test logout revokes user session family.
     */
    public function test_logout_revokes_user_session_and_refresh_tokens(): void
    {
        $loginRes = $this->postJson('/api/v1/auth/login', [
            'email' => 'cashier@sunnycafe.kh',
            'password' => 'Password123!',
        ])->assertOk();

        $accessToken = $loginRes->json('access_token');
        $refreshToken = $loginRes->json('refresh_token');
        $sessionUuid = $loginRes->json('session_uuid');

        // Logout
        $this->withHeader('Authorization', 'Bearer ' . $accessToken)
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        // Verify session revoked
        $userSession = UserSession::where('uuid', $sessionUuid)->first();
        $this->assertNotNull($userSession->revoked_at);
        $this->assertEquals('user_logged_out', $userSession->revocation_reason);

        // Refresh with family token is now unauthorized
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $refreshToken])
            ->assertUnauthorized();

        // Protected routes reject revoked session
        $this->withHeader('Authorization', 'Bearer ' . $accessToken)
            ->getJson('/api/v1/me')
            ->assertUnauthorized();
    }
}
