<?php

namespace Tests\Feature;

use App\Models\SecurityEvent;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SecurityEventsApiTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenantA;
    protected Tenant $tenantB;
    protected User $adminUserA;
    protected User $adminUserB;
    protected User $superAdmin;
    protected User $cashierUserA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = Tenant::create([
            'name' => 'Security Audit Alpha',
            'slug' => 'sec-alpha',
            'status' => 'active',
        ]);

        $this->tenantB = Tenant::create([
            'name' => 'Security Audit Beta',
            'slug' => 'sec-beta',
            'status' => 'active',
        ]);

        $this->adminUserA = User::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'role' => 'admin',
            'email' => 'admin.sec@alpha.com',
            'is_active' => true,
        ]);

        $this->adminUserB = User::factory()->create([
            'tenant_id' => $this->tenantB->id,
            'role' => 'admin',
            'email' => 'admin.sec@beta.com',
            'is_active' => true,
        ]);

        $this->superAdmin = User::factory()->create([
            'tenant_id' => null,
            'role' => 'super_admin',
            'email' => 'super.admin@platform.com',
            'is_active' => true,
        ]);

        $this->cashierUserA = User::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'role' => 'cashier',
            'email' => 'cashier.sec@alpha.com',
            'is_active' => true,
        ]);
    }

    public function test_unauthenticated_access_is_blocked(): void
    {
        $this->getJson('/api/v1/security-events')->assertUnauthorized();
        $this->getJson('/api/v1/security-events/stats')->assertUnauthorized();
        $this->getJson('/api/v1/security-events/1')->assertUnauthorized();
    }

    public function test_unauthorized_role_is_forbidden(): void
    {
        $this->actingAs($this->cashierUserA)
            ->getJson('/api/v1/security-events')
            ->assertForbidden();
    }

    public function test_admin_can_list_tenant_scoped_security_events(): void
    {
        // Event in Tenant A
        $eventA = SecurityEvent::create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $this->tenantA->id,
            'user_id' => $this->adminUserA->id,
            'request_id' => 'req-alpha-1',
            'event_type' => 'failed_login',
            'severity' => 'medium',
            'outcome' => 'blocked',
            'summary' => 'Failed login attempt on Tenant Alpha',
            'status_code' => 401,
            'occurred_at' => now(),
        ]);

        // Event in Tenant B
        $eventB = SecurityEvent::create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $this->tenantB->id,
            'user_id' => $this->adminUserB->id,
            'request_id' => 'req-beta-1',
            'event_type' => 'replay_attack',
            'severity' => 'critical',
            'outcome' => 'blocked',
            'summary' => 'Replay attack on Tenant Beta',
            'status_code' => 401,
            'occurred_at' => now(),
        ]);

        $response = $this->actingAs($this->adminUserA)
            ->getJson('/api/v1/security-events');

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.uuid', $eventA->uuid)
            ->assertJsonPath('data.0.tenant_id', $this->tenantA->id);

        // Verify Tenant B event is NOT present
        $uuids = collect($response->json('data'))->pluck('uuid')->toArray();
        $this->assertNotContains($eventB->uuid, $uuids);
    }

    public function test_security_event_filtering_by_severity_and_type(): void
    {
        SecurityEvent::create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $this->tenantA->id,
            'event_type' => 'jwt_invalid_signature',
            'severity' => 'high',
            'outcome' => 'blocked',
            'summary' => 'Tampered JWT signature',
            'status_code' => 401,
            'occurred_at' => now(),
        ]);

        SecurityEvent::create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $this->tenantA->id,
            'event_type' => 'pos_scope_violation',
            'severity' => 'medium',
            'outcome' => 'blocked',
            'summary' => 'POS scope violation',
            'status_code' => 403,
            'occurred_at' => now(),
        ]);

        // Filter severity=high
        $responseHigh = $this->actingAs($this->adminUserA)
            ->getJson('/api/v1/security-events?severity=high');
        $responseHigh->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.event_type', 'jwt_invalid_signature');

        // Filter event_type=pos_scope_violation
        $responseType = $this->actingAs($this->adminUserA)
            ->getJson('/api/v1/security-events?event_type=pos_scope_violation');
        $responseType->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.event_type', 'pos_scope_violation');
    }

    public function test_super_admin_can_view_all_and_filter_by_tenant(): void
    {
        SecurityEvent::create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $this->tenantA->id,
            'event_type' => 'event_a',
            'severity' => 'low',
            'outcome' => 'blocked',
            'summary' => 'Event A',
            'status_code' => 401,
            'occurred_at' => now(),
        ]);

        SecurityEvent::create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $this->tenantB->id,
            'event_type' => 'event_b',
            'severity' => 'high',
            'outcome' => 'blocked',
            'summary' => 'Event B',
            'status_code' => 401,
            'occurred_at' => now(),
        ]);

        // Super Admin gets all events (total 2)
        $responseAll = $this->actingAs($this->superAdmin)
            ->getJson('/api/v1/security-events');
        $responseAll->assertOk()
            ->assertJsonPath('meta.total', 2);

        // Super Admin filters by tenant_id
        $responseFiltered = $this->actingAs($this->superAdmin)
            ->getJson('/api/v1/security-events?tenant_id=' . $this->tenantB->id);
        $responseFiltered->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.event_type', 'event_b');
    }

    public function test_security_events_stats_endpoint(): void
    {
        SecurityEvent::create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $this->tenantA->id,
            'event_type' => 'failed_login',
            'severity' => 'critical',
            'outcome' => 'blocked',
            'summary' => 'Critical Event',
            'status_code' => 401,
            'occurred_at' => now(),
        ]);

        SecurityEvent::create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $this->tenantA->id,
            'event_type' => 'failed_login',
            'severity' => 'medium',
            'outcome' => 'blocked',
            'summary' => 'Medium Event',
            'status_code' => 401,
            'occurred_at' => now(),
        ]);

        $response = $this->actingAs($this->adminUserA)
            ->getJson('/api/v1/security-events/stats');

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.total_events', 2)
            ->assertJsonPath('data.by_severity.critical', 1)
            ->assertJsonPath('data.by_severity.medium', 1)
            ->assertJsonPath('data.top_event_types.failed_login', 2);
    }

    public function test_show_security_event_details_and_tenant_isolation(): void
    {
        $eventA = SecurityEvent::create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $this->tenantA->id,
            'event_type' => 'token_revoked',
            'severity' => 'high',
            'outcome' => 'blocked',
            'summary' => 'Token was revoked',
            'metadata' => [
                'token' => 'plain_secret_token_12345',
                'reason' => 'user_logged_out',
            ],
            'status_code' => 401,
            'occurred_at' => now(),
        ]);

        // Tenant A admin can view
        $responseA = $this->actingAs($this->adminUserA)
            ->getJson('/api/v1/security-events/' . $eventA->id);
        $responseA->assertOk()
            ->assertJsonPath('data.uuid', $eventA->uuid)
            ->assertJsonPath('data.metadata.reason', 'user_logged_out')
            ->assertJsonPath('data.metadata.token', '[REDACTED]');

        // Can also view via UUID
        $this->actingAs($this->adminUserA)
            ->getJson('/api/v1/security-events/' . $eventA->uuid)
            ->assertOk();

        // Tenant B admin CANNOT view Tenant A's event (404)
        $this->actingAs($this->adminUserB)
            ->getJson('/api/v1/security-events/' . $eventA->id)
            ->assertNotFound();
    }

    /**
     * Test admin can view and update automated threat defense settings.
     */
    public function test_threat_defense_settings_can_be_viewed_and_updated(): void
    {
        $response = $this->actingAs($this->superAdmin)
            ->getJson('/api/v1/security-events/settings');

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure([
                'data' => ['enabled', 'max_offenses', 'offense_decay_minutes', 'ban_duration_minutes', 'whitelist', 'active_banned_ips_count'],
            ]);

        // Update settings
        $updateRes = $this->actingAs($this->superAdmin)
            ->putJson('/api/v1/security-events/settings', [
                'max_offenses' => 5,
                'ban_duration_minutes' => 120,
            ]);

        $updateRes->assertOk()
            ->assertJsonPath('data.max_offenses', 5)
            ->assertJsonPath('data.ban_duration_minutes', 120);

        // Reset settings
        \App\Services\IpThreatDefenseService::updateSettings([
            'max_offenses' => 3,
            'ban_duration_minutes' => 60,
        ]);
    }

    /**
     * Test admin can list, manually ban, and unban IPs.
     */
    public function test_admin_can_list_ban_and_unban_ips(): void
    {
        $targetIp = '198.51.100.99';

        // Ban IP
        $banRes = $this->actingAs($this->superAdmin)
            ->postJson('/api/v1/security-events/ban-ip', [
                'ip' => $targetIp,
                'duration_minutes' => 30,
                'reason' => 'Testing manual ban',
            ]);

        $banRes->assertStatus(201)
            ->assertJsonPath('data.ip', $targetIp)
            ->assertJsonPath('data.duration_minutes', 30);

        // Verify in banned list
        $listRes = $this->actingAs($this->superAdmin)
            ->getJson('/api/v1/security-events/banned-ips');

        $listRes->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.ip', $targetIp);

        // Unban IP
        $unbanRes = $this->actingAs($this->superAdmin)
            ->postJson('/api/v1/security-events/unban-ip', [
                'ip' => $targetIp,
            ]);

        $unbanRes->assertOk()
            ->assertJsonPath('status', 'success');

        // Verify banned list is now empty
        $afterList = $this->actingAs($this->superAdmin)
            ->getJson('/api/v1/security-events/banned-ips');
        $afterList->assertOk()
            ->assertJsonPath('total', 0);
    }

    /**
     * Test multiple SQL injection attempts from same source IP triggers automatic IP ban.
     */
    public function test_multiple_sql_injection_attempts_from_same_ip_auto_bans_the_ip(): void
    {
        $attackerIp = '203.0.113.88';

        // Ensure IP is clean
        \App\Services\IpThreatDefenseService::unbanIp($attackerIp);

        // 1st SQL injection attempt
        $res1 = $this->withServerVariables(['REMOTE_ADDR' => $attackerIp])
            ->getJson("/api/v1/users?search=" . urlencode("admin' OR 1=1--"));
        $res1->assertStatus(403)
            ->assertJsonPath('code', 'SQL_INJECTION_DETECTED');

        // 2nd SQL injection attempt
        $res2 = $this->withServerVariables(['REMOTE_ADDR' => $attackerIp])
            ->getJson("/api/v1/users?search=" . urlencode("' UNION SELECT 1,2,3--"));
        $res2->assertStatus(403)
            ->assertJsonPath('code', 'SQL_INJECTION_DETECTED');

        // 3rd SQL injection attempt -> crosses threshold (3 attempts), triggers auto-ban!
        $res3 = $this->withServerVariables(['REMOTE_ADDR' => $attackerIp])
            ->getJson("/api/v1/users?search=" . urlencode("1; DROP TABLE users;--"));
        $res3->assertStatus(403);

        // Verify IP is now banned
        $this->assertNotNull(\App\Services\IpThreatDefenseService::isBanned($attackerIp));

        // 4th request from same IP (even a benign request) is immediately rejected with IP_AUTO_BANNED
        $res4 = $this->withServerVariables(['REMOTE_ADDR' => $attackerIp])
            ->getJson('/api/v1/users');
        $res4->assertStatus(403)
            ->assertJsonPath('code', 'IP_AUTO_BANNED')
            ->assertJsonPath('data.ip', $attackerIp);

        // Clean up
        \App\Services\IpThreatDefenseService::unbanIp($attackerIp);
    }

    /**
     * Acceptance (P1): Tenant admins (and tenant-scoped super admins) receive 403 for global IP ban,
     * unban, settings, and banned-IP listing, and Tenant A cannot block Tenant B's requests.
     */
    public function test_tenant_admin_cannot_access_global_ip_ban_controls_or_block_other_tenants(): void
    {
        $targetIp = '198.51.100.77';
        \App\Services\IpThreatDefenseService::unbanIp($targetIp);

        // Tenant A admin receives 403 across all global threat-defense endpoints
        $this->actingAs($this->adminUserA)
            ->getJson('/api/v1/security-events/settings')
            ->assertForbidden();

        $this->actingAs($this->adminUserA)
            ->putJson('/api/v1/security-events/settings', ['enabled' => false])
            ->assertForbidden();

        $this->actingAs($this->adminUserA)
            ->getJson('/api/v1/security-events/banned-ips')
            ->assertForbidden();

        $this->actingAs($this->adminUserA)
            ->postJson('/api/v1/security-events/ban-ip', [
                'ip' => $targetIp,
                'duration_minutes' => 60,
                'reason' => 'Cross-tenant block attempt',
            ])
            ->assertForbidden();

        // Global ban cache entry MUST NOT be created
        $this->assertNull(\App\Services\IpThreatDefenseService::isBanned($targetIp));

        // Tenant B user sending requests from $targetIp remains completely unaffected
        $this->actingAs($this->adminUserB)
            ->withServerVariables(['REMOTE_ADDR' => $targetIp])
            ->getJson('/api/v1/security-events')
            ->assertOk();

        // Platform super admin CAN ban and unban globally
        $this->actingAs($this->superAdmin)
            ->postJson('/api/v1/security-events/ban-ip', [
                'ip' => $targetIp,
                'duration_minutes' => 15,
                'reason' => 'Platform incident ban',
            ])
            ->assertStatus(201);

        $this->assertNotNull(\App\Services\IpThreatDefenseService::isBanned($targetIp));

        // Tenant A admin CANNOT remove a platform ban (testing from a non-banned client IP)
        $this->actingAs($this->adminUserA)
            ->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->postJson('/api/v1/security-events/unban-ip', ['ip' => $targetIp])
            ->assertForbidden();

        $this->assertNotNull(\App\Services\IpThreatDefenseService::isBanned($targetIp));

        // Platform super admin CAN unban
        $this->actingAs($this->superAdmin)
            ->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->postJson('/api/v1/security-events/unban-ip', ['ip' => $targetIp])
            ->assertOk();

        $this->assertNull(\App\Services\IpThreatDefenseService::isBanned($targetIp));
    }
}

