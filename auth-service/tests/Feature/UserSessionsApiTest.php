<?php

namespace Tests\Feature;

use App\Models\ApiClient;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class UserSessionsApiTest extends TestCase
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
            'name' => 'Session Tenant Alpha',
            'slug' => 'sess-alpha',
            'status' => 'active',
        ]);

        $this->tenantB = Tenant::create([
            'name' => 'Session Tenant Beta',
            'slug' => 'sess-beta',
            'status' => 'active',
        ]);

        $this->adminUserA = User::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'role' => 'admin',
            'email' => 'admin.sess@alpha.com',
            'is_active' => true,
        ]);

        $this->adminUserB = User::factory()->create([
            'tenant_id' => $this->tenantB->id,
            'role' => 'admin',
            'email' => 'admin.sess@beta.com',
            'is_active' => true,
        ]);

        $this->cashierUserA = User::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'role' => 'cashier',
            'email' => 'cashier.sess@alpha.com',
            'is_active' => true,
        ]);

        $this->client = ApiClient::create([
            'name' => 'POS Desktop Terminal',
            'client_key' => 'ak_live_' . Str::random(32),
            'client_type' => 'pos',
            'is_active' => true,
        ]);
    }

    public function test_unauthenticated_access_is_blocked(): void
    {
        $this->getJson('/api/v1/user-sessions')->assertUnauthorized();
        $this->getJson('/api/v1/user-sessions/stats')->assertUnauthorized();
        $this->getJson('/api/v1/user-sessions/1')->assertUnauthorized();
    }

    public function test_unauthorized_role_is_forbidden(): void
    {
        $this->actingAs($this->cashierUserA)
            ->getJson('/api/v1/user-sessions')
            ->assertForbidden();
    }

    public function test_admin_can_list_tenant_scoped_user_sessions(): void
    {
        $sessA = UserSession::create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $this->tenantA->id,
            'user_id' => $this->adminUserA->id,
            'api_client_id' => $this->client->id,
            'device_name' => 'iPad Counter 1',
            'ip_address' => '192.168.1.10',
        ]);

        $sessB = UserSession::create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $this->tenantB->id,
            'user_id' => $this->adminUserB->id,
            'api_client_id' => $this->client->id,
            'device_name' => 'iPad Counter 2',
            'ip_address' => '192.168.2.20',
        ]);

        $response = $this->actingAs($this->adminUserA)
            ->getJson('/api/v1/user-sessions');

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.uuid', $sessA->uuid)
            ->assertJsonPath('data.0.device_name', 'iPad Counter 1');

        $uuids = collect($response->json('data'))->pluck('uuid')->toArray();
        $this->assertNotContains($sessB->uuid, $uuids);
    }

    public function test_user_session_stats_endpoint(): void
    {
        // Active session
        UserSession::create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $this->tenantA->id,
            'user_id' => $this->adminUserA->id,
            'api_client_id' => $this->client->id,
            'device_name' => 'Device Active',
        ]);

        // Revoked session
        UserSession::create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $this->tenantA->id,
            'user_id' => $this->adminUserA->id,
            'api_client_id' => $this->client->id,
            'device_name' => 'Device Revoked',
            'revoked_at' => now(),
            'revocation_reason' => 'user_logged_out',
        ]);

        $response = $this->actingAs($this->adminUserA)
            ->getJson('/api/v1/user-sessions/stats');

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.total_sessions', 2)
            ->assertJsonPath('data.active_sessions', 1)
            ->assertJsonPath('data.revoked_sessions', 1)
            ->assertJsonPath('data.active_users_count', 1);
    }

    public function test_show_user_session_and_revoke(): void
    {
        $session = UserSession::create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $this->tenantA->id,
            'user_id' => $this->adminUserA->id,
            'api_client_id' => $this->client->id,
            'device_name' => 'Main POS Register',
            'ip_address' => '10.0.0.50',
        ]);

        // Show endpoint
        $showResponse = $this->actingAs($this->adminUserA)
            ->getJson('/api/v1/user-sessions/' . $session->id);
        $showResponse->assertOk()
            ->assertJsonPath('data.uuid', $session->uuid)
            ->assertJsonPath('data.device_name', 'Main POS Register')
            ->assertJsonPath('data.is_active', true);

        // Revoke endpoint
        $revokeResponse = $this->actingAs($this->adminUserA)
            ->postJson('/api/v1/user-sessions/' . $session->id . '/revoke', [
                'reason' => 'security_audit',
            ]);
        $revokeResponse->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertNotNull($session->fresh()->revoked_at);
        $this->assertEquals('security_audit', $session->fresh()->revocation_reason);
    }

    public function test_revoke_all_sessions_for_tenant(): void
    {
        UserSession::create([
            'tenant_id' => $this->tenantA->id,
            'user_id' => $this->adminUserA->id,
            'api_client_id' => $this->client->id,
            'device_name' => 'Terminal 1',
        ]);

        UserSession::create([
            'tenant_id' => $this->tenantA->id,
            'user_id' => $this->adminUserA->id,
            'api_client_id' => $this->client->id,
            'device_name' => 'Terminal 2',
        ]);

        $response = $this->actingAs($this->adminUserA)
            ->postJson('/api/v1/user-sessions/revoke-all', [
                'reason' => 'emergency_security_flush',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.revoked_count', 2);

        $activeRemaining = UserSession::where('tenant_id', $this->tenantA->id)->whereNull('revoked_at')->count();
        $this->assertEquals(0, $activeRemaining);
    }

    public function test_super_admin_can_retrieve_user_session_stats_across_all_tenants(): void
    {
        $superAdmin = User::factory()->create([
            'email' => 'superadmin.sess@codebridges.io',
            'role' => 'super_admin',
            'tenant_id' => $this->tenantA->id,
            'is_active' => true,
        ]);

        UserSession::create([
            'tenant_id' => $this->tenantA->id,
            'user_id' => $this->adminUserA->id,
            'api_client_id' => $this->client->id,
            'device_name' => 'Session A',
        ]);

        UserSession::create([
            'tenant_id' => $this->tenantB->id,
            'user_id' => $this->adminUserB->id,
            'api_client_id' => $this->client->id,
            'device_name' => 'Session B',
        ]);

        // Super Admin sees all sessions across tenants
        $response = $this->actingAs($superAdmin)
            ->getJson('/api/v1/user-sessions/stats');

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.total_sessions', 2)
            ->assertJsonPath('data.active_sessions', 2);

        // Super Admin can filter by tenant_id
        $filteredResponse = $this->actingAs($superAdmin)
            ->getJson('/api/v1/user-sessions/stats?tenant_id=' . $this->tenantA->id);

        $filteredResponse->assertOk()
            ->assertJsonPath('data.total_sessions', 1)
            ->assertJsonPath('data.active_sessions', 1);
    }

    public function test_user_can_retrieve_session_stats_when_session_tenant_id_is_null(): void
    {
        $adminNullTenant = User::factory()->create([
            'email' => 'admin.nullsess@example.com',
            'role' => 'admin',
            'tenant_id' => $this->tenantA->id,
            'is_active' => true,
        ]);

        UserSession::create([
            'tenant_id' => null,
            'user_id' => $adminNullTenant->id,
            'api_client_id' => $this->client->id,
            'device_name' => 'Session Null Tenant',
        ]);

        $response = $this->actingAs($adminNullTenant)
            ->getJson('/api/v1/user-sessions/stats');

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.total_sessions', 1)
            ->assertJsonPath('data.active_sessions', 1);
    }
}

