<?php

namespace Tests\Feature;

use App\Models\ApiClient;
use App\Models\RefreshToken;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class RefreshTokensApiTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenantA;
    protected Tenant $tenantB;
    protected User $adminUserA;
    protected User $adminUserB;
    protected User $cashierUserA;
    protected ApiClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = Tenant::create([
            'name' => 'Refresh Tenant Alpha',
            'slug' => 'rt-alpha',
            'status' => 'active',
        ]);

        $this->tenantB = Tenant::create([
            'name' => 'Refresh Tenant Beta',
            'slug' => 'rt-beta',
            'status' => 'active',
        ]);

        $this->adminUserA = User::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'role' => 'admin',
            'email' => 'admin.rt@alpha.com',
            'is_active' => true,
        ]);

        $this->adminUserB = User::factory()->create([
            'tenant_id' => $this->tenantB->id,
            'role' => 'admin',
            'email' => 'admin.rt@beta.com',
            'is_active' => true,
        ]);

        $this->cashierUserA = User::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'role' => 'cashier',
            'email' => 'cashier.rt@alpha.com',
            'is_active' => true,
        ]);

        $this->client = ApiClient::create([
            'name' => 'Web Admin Portal',
            'client_key' => 'ak_live_' . Str::random(32),
            'client_type' => 'web_admin',
            'is_active' => true,
        ]);
    }

    public function test_unauthenticated_access_is_blocked(): void
    {
        $this->getJson('/api/v1/refresh-tokens')->assertUnauthorized();
        $this->getJson('/api/v1/refresh-tokens/stats')->assertUnauthorized();
        $this->getJson('/api/v1/refresh-tokens/1')->assertUnauthorized();
    }

    public function test_unauthorized_role_is_forbidden(): void
    {
        $this->actingAs($this->cashierUserA)
            ->getJson('/api/v1/refresh-tokens')
            ->assertForbidden();
    }

    public function test_admin_can_list_tenant_scoped_refresh_tokens(): void
    {
        $sessionA = UserSession::create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $this->tenantA->id,
            'user_id' => $this->adminUserA->id,
            'api_client_id' => $this->client->id,
            'device_name' => 'MacBook Pro A',
            'ip_address' => '127.0.0.1',
        ]);

        $tokenA = RefreshToken::create([
            'session_id' => $sessionA->id,
            'token_id' => bin2hex(random_bytes(16)),
            'token_hash' => hash('sha256', 'dummy_a'),
            'expires_at' => Carbon::now()->addDays(30),
            'created_at' => now(),
        ]);

        $sessionB = UserSession::create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $this->tenantB->id,
            'user_id' => $this->adminUserB->id,
            'api_client_id' => $this->client->id,
            'device_name' => 'MacBook Pro B',
            'ip_address' => '10.0.0.1',
        ]);

        $tokenB = RefreshToken::create([
            'session_id' => $sessionB->id,
            'token_id' => bin2hex(random_bytes(16)),
            'token_hash' => hash('sha256', 'dummy_b'),
            'expires_at' => Carbon::now()->addDays(30),
            'created_at' => now(),
        ]);

        $response = $this->actingAs($this->adminUserA)
            ->getJson('/api/v1/refresh-tokens');

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.token_id', $tokenA->token_id);

        $tokenIds = collect($response->json('data'))->pluck('token_id')->toArray();
        $this->assertNotContains($tokenB->token_id, $tokenIds);
    }

    public function test_refresh_token_stats_endpoint(): void
    {
        $session = UserSession::create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $this->tenantA->id,
            'user_id' => $this->adminUserA->id,
            'api_client_id' => $this->client->id,
            'device_name' => 'Test Laptop',
        ]);

        // Active token
        RefreshToken::create([
            'session_id' => $session->id,
            'token_id' => bin2hex(random_bytes(16)),
            'token_hash' => hash('sha256', 'active_1'),
            'expires_at' => Carbon::now()->addDays(30),
            'created_at' => now(),
        ]);

        // Consumed token
        RefreshToken::create([
            'session_id' => $session->id,
            'token_id' => bin2hex(random_bytes(16)),
            'token_hash' => hash('sha256', 'consumed_1'),
            'consumed_at' => now(),
            'expires_at' => Carbon::now()->addDays(30),
            'created_at' => now(),
        ]);

        // Revoked token
        RefreshToken::create([
            'session_id' => $session->id,
            'token_id' => bin2hex(random_bytes(16)),
            'token_hash' => hash('sha256', 'revoked_1'),
            'revoked_at' => now(),
            'expires_at' => Carbon::now()->addDays(30),
            'created_at' => now(),
        ]);

        $response = $this->actingAs($this->adminUserA)
            ->getJson('/api/v1/refresh-tokens/stats');

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.total_tokens', 3)
            ->assertJsonPath('data.active_count', 1)
            ->assertJsonPath('data.consumed_count', 1)
            ->assertJsonPath('data.revoked_count', 1);
    }

    public function test_show_refresh_token_and_revoke_family(): void
    {
        $session = UserSession::create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $this->tenantA->id,
            'user_id' => $this->adminUserA->id,
            'api_client_id' => $this->client->id,
            'device_name' => 'Office PC',
        ]);

        $token = RefreshToken::create([
            'session_id' => $session->id,
            'token_id' => bin2hex(random_bytes(16)),
            'token_hash' => hash('sha256', 'secret_raw'),
            'expires_at' => Carbon::now()->addDays(30),
            'created_at' => now(),
        ]);

        // Show endpoint
        $showResponse = $this->actingAs($this->adminUserA)
            ->getJson('/api/v1/refresh-tokens/' . $token->id);
        $showResponse->assertOk()
            ->assertJsonPath('data.token_id', $token->token_id)
            ->assertJsonPath('data.is_active', true);

        // Revoke endpoint
        $revokeResponse = $this->actingAs($this->adminUserA)
            ->postJson('/api/v1/refresh-tokens/' . $token->id . '/revoke');
        $revokeResponse->assertOk()
            ->assertJsonPath('status', 'success');

        // Check token and session are revoked
        $this->assertNotNull($token->fresh()->revoked_at);
        $this->assertNotNull($session->fresh()->revoked_at);
    }

    public function test_super_admin_can_retrieve_aggregate_stats_across_all_tenants(): void
    {
        $superAdmin = User::factory()->create([
            'email' => 'superadmin.test@codebridges.io',
            'role' => 'super_admin',
            'tenant_id' => $this->tenantA->id,
            'is_active' => true,
        ]);

        $sessionA = UserSession::create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $this->tenantA->id,
            'user_id' => $this->adminUserA->id,
            'api_client_id' => $this->client->id,
            'device_name' => 'Session A',
        ]);
        RefreshToken::create([
            'session_id' => $sessionA->id,
            'token_id' => bin2hex(random_bytes(16)),
            'token_hash' => hash('sha256', 'hash_a'),
            'expires_at' => Carbon::now()->addDays(30),
            'created_at' => now(),
        ]);

        $sessionB = UserSession::create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $this->tenantB->id,
            'user_id' => $this->adminUserB->id,
            'api_client_id' => $this->client->id,
            'device_name' => 'Session B',
        ]);
        RefreshToken::create([
            'session_id' => $sessionB->id,
            'token_id' => bin2hex(random_bytes(16)),
            'token_hash' => hash('sha256', 'hash_b'),
            'expires_at' => Carbon::now()->addDays(30),
            'created_at' => now(),
        ]);

        // Super Admin sees all tokens across tenantA and tenantB without filtering
        $response = $this->actingAs($superAdmin)
            ->getJson('/api/v1/refresh-tokens/stats');

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.total_tokens', 2)
            ->assertJsonPath('data.active_count', 2);

        // Super Admin can filter explicitly by tenant_id
        $filteredResponse = $this->actingAs($superAdmin)
            ->getJson('/api/v1/refresh-tokens/stats?tenant_id=' . $this->tenantA->id);

        $filteredResponse->assertOk()
            ->assertJsonPath('data.total_tokens', 1)
            ->assertJsonPath('data.active_count', 1);
    }

    public function test_user_can_retrieve_stats_when_session_tenant_id_is_null(): void
    {
        $adminNullTenantSession = User::factory()->create([
            'email' => 'admin.nulltenant@example.com',
            'role' => 'admin',
            'tenant_id' => $this->tenantA->id,
            'is_active' => true,
        ]);

        // Session created with null tenant_id
        $session = UserSession::create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => null,
            'user_id' => $adminNullTenantSession->id,
            'api_client_id' => $this->client->id,
            'device_name' => 'Device Null Tenant',
        ]);
        RefreshToken::create([
            'session_id' => $session->id,
            'token_id' => bin2hex(random_bytes(16)),
            'token_hash' => hash('sha256', 'hash_null'),
            'expires_at' => Carbon::now()->addDays(30),
            'created_at' => now(),
        ]);

        $response = $this->actingAs($adminNullTenantSession)
            ->getJson('/api/v1/refresh-tokens/stats');

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.total_tokens', 1)
            ->assertJsonPath('data.active_count', 1);
    }
}

