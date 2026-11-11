<?php

namespace Tests\Feature\Security;

use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RejectExpiredSanctumTokenTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Acme Corporation',
            'slug' => 'acme-corp',
            'status' => 'active',
        ]);

        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'email' => 'sanctum.tester@acme.corp',
            'is_active' => true,
            'session_inactivity_days' => 7,
        ]);
    }

    /**
     * Acceptance: Using real bearer credentials against /api/v1/me, a valid token returns 200.
     */
    public function test_valid_sanctum_token_returns_ok_on_me_endpoint(): void
    {
        $newToken = $this->user->createToken('DesktopDevice');
        $plainToken = $newToken->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $plainToken)
            ->getJson('/api/v1/me');

        $response->assertOk()
            ->assertJsonPath('user.id', $this->user->id)
            ->assertJsonPath('user.email', $this->user->email);
    }

    /**
     * Acceptance: Using real bearer credentials against /api/v1/me, expired tokens return 401.
     */
    public function test_expired_sanctum_token_by_expires_at_returns_unauthorized(): void
    {
        $newToken = $this->user->createToken('ExpiringDevice', ['*'], Carbon::now()->subMinute());
        $plainToken = $newToken->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $plainToken)
            ->getJson('/api/v1/me');

        $response->assertStatus(401)
            ->assertJson([
                'status' => 'error',
                'code' => 'TOKEN_EXPIRED',
                'message' => 'Token has expired.',
            ]);
    }

    /**
     * Acceptance: Expired token via configured Sanctum expiration minutes returns 401.
     */
    public function test_expired_sanctum_token_by_sanctum_expiration_config_returns_unauthorized(): void
    {
        Config::set('sanctum.expiration', 60); // 60 minutes

        $newToken = $this->user->createToken('ConfigExpiringDevice');
        $plainToken = $newToken->plainTextToken;

        // Simulate created 65 minutes ago
        DB::table('personal_access_tokens')
            ->where('id', $newToken->accessToken->id)
            ->update([
                'created_at' => Carbon::now()->subMinutes(65),
            ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $plainToken)
            ->getJson('/api/v1/me');

        $response->assertStatus(401)
            ->assertJson([
                'status' => 'error',
                'code' => 'TOKEN_EXPIRED',
            ]);
    }

    /**
     * Acceptance: Include custom session-expiry coverage; avoid actingAs for these tests.
     */
    public function test_sanctum_token_respects_custom_user_session_inactivity_timeout(): void
    {
        // User has session_inactivity_days = 7
        $this->user->update(['session_inactivity_days' => 5]);

        $newToken = $this->user->createToken('InactiveDevice');
        $plainToken = $newToken->plainTextToken;

        // Set last_used_at to 6 days ago (exceeds 5 days inactivity limit)
        DB::table('personal_access_tokens')
            ->where('id', $newToken->accessToken->id)
            ->update([
                'last_used_at' => Carbon::now()->subDays(6),
                'created_at' => Carbon::now()->subDays(6),
            ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $plainToken)
            ->getJson('/api/v1/me');

        $response->assertStatus(401)
            ->assertJson([
                'status' => 'error',
                'code' => 'SESSION_EXPIRED',
                'message' => 'Session has expired due to inactivity.',
            ]);

        // Token row must be pruned from database
        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $newToken->accessToken->id,
        ]);
    }

    /**
     * Acceptance: Expired token via custom session_expires_at column returns 401 without actingAs.
     */
    public function test_expired_sanctum_token_by_custom_session_expires_at_returns_unauthorized(): void
    {
        $newToken = $this->user->createToken('SessionExpiredDevice');
        $plainToken = $newToken->plainTextToken;

        DB::table('personal_access_tokens')
            ->where('id', $newToken->accessToken->id)
            ->update([
                'expires_at' => null,
                'session_expires_at' => Carbon::now()->subMinute(),
            ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $plainToken)
            ->getJson('/api/v1/me');

        $response->assertStatus(401)
            ->assertJson([
                'status' => 'error',
                'code' => 'SESSION_EXPIRED',
                'message' => 'Session has expired.',
            ]);

        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $newToken->accessToken->id,
        ]);
    }

    /**
     * Acceptance: Logout invalidates that same token, subsequent calls return 401.
     */
    public function test_logout_invalidates_current_sanctum_token(): void
    {
        $newToken = $this->user->createToken('LogoutDevice');
        $plainToken = $newToken->plainTextToken;

        // Verify valid first
        $meBefore = $this->withHeader('Authorization', 'Bearer ' . $plainToken)
            ->getJson('/api/v1/me');
        $meBefore->assertOk();

        // Perform logout with the real bearer token
        $logoutResponse = $this->withHeader('Authorization', 'Bearer ' . $plainToken)
            ->postJson('/api/v1/auth/logout');
        $logoutResponse->assertOk()
            ->assertJson(['status' => 'success']);

        // Verify that the token was deleted from the personal_access_tokens table
        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $newToken->accessToken->id,
        ]);

        // Next access with the same token must be rejected with 401
        $meAfter = $this->withHeader('Authorization', 'Bearer ' . $plainToken)
            ->getJson('/api/v1/me');
        $meAfter->assertStatus(401);
    }
}
