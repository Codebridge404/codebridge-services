<?php

namespace Tests\Feature;

use App\Models\ApiRequestLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ApiRequestLogsApiTest extends TestCase
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
            'name' => 'Telemetry Alpha',
            'slug' => 'telem-alpha',
            'status' => 'active',
        ]);

        $this->tenantB = Tenant::create([
            'name' => 'Telemetry Beta',
            'slug' => 'telem-beta',
            'status' => 'active',
        ]);

        $this->adminUserA = User::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'role' => 'admin',
            'email' => 'admin.telem@alpha.com',
            'is_active' => true,
        ]);

        $this->adminUserB = User::factory()->create([
            'tenant_id' => $this->tenantB->id,
            'role' => 'admin',
            'email' => 'admin.telem@beta.com',
            'is_active' => true,
        ]);

        $this->superAdmin = User::factory()->create([
            'tenant_id' => null,
            'role' => 'super_admin',
            'email' => 'super.telem@platform.com',
            'is_active' => true,
        ]);

        $this->cashierUserA = User::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'role' => 'cashier',
            'email' => 'cashier.telem@alpha.com',
            'is_active' => true,
        ]);
    }

    public function test_unauthenticated_access_is_blocked(): void
    {
        $this->getJson('/api/v1/request-logs')->assertUnauthorized();
        $this->getJson('/api/v1/request-logs/stats')->assertUnauthorized();
        $this->getJson('/api/v1/request-logs/1')->assertUnauthorized();
    }

    public function test_unauthorized_role_is_forbidden(): void
    {
        $this->actingAs($this->cashierUserA)
            ->getJson('/api/v1/request-logs')
            ->assertForbidden();
    }

    public function test_admin_can_list_tenant_scoped_request_logs(): void
    {
        $logA = ApiRequestLog::create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $this->tenantA->id,
            'user_id' => $this->adminUserA->id,
            'method' => 'GET',
            'path' => 'api/v1/me',
            'status_code' => 200,
            'duration_ms' => 45,
            'ip_address' => '127.0.0.1',
        ]);

        $logB = ApiRequestLog::create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $this->tenantB->id,
            'user_id' => $this->adminUserB->id,
            'method' => 'POST',
            'path' => 'api/v1/meetings',
            'status_code' => 201,
            'duration_ms' => 120,
            'ip_address' => '10.0.0.2',
        ]);

        $response = $this->actingAs($this->adminUserA)
            ->getJson('/api/v1/request-logs');

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.uuid', $logA->uuid)
            ->assertJsonPath('data.0.path', 'api/v1/me');

        $uuids = collect($response->json('data'))->pluck('uuid')->toArray();
        $this->assertNotContains($logB->uuid, $uuids);
    }

    public function test_request_logs_filtering_by_method_status_and_latency(): void
    {
        ApiRequestLog::create([
            'tenant_id' => $this->tenantA->id,
            'method' => 'GET',
            'path' => 'api/v1/users',
            'status_code' => 200,
            'duration_ms' => 30,
        ]);

        ApiRequestLog::create([
            'tenant_id' => $this->tenantA->id,
            'method' => 'POST',
            'path' => 'api/v1/users',
            'status_code' => 422,
            'duration_ms' => 800,
            'error_code' => 'VALIDATION_FAILED',
        ]);

        // Filter method=POST
        $responsePost = $this->actingAs($this->adminUserA)
            ->getJson('/api/v1/request-logs?method=POST');
        $responsePost->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.method', 'POST');

        // Filter status_code=422
        $response422 = $this->actingAs($this->adminUserA)
            ->getJson('/api/v1/request-logs?status_code=422');
        $response422->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.status_code', 422);

        // Filter min_duration_ms=500
        $responseSlow = $this->actingAs($this->adminUserA)
            ->getJson('/api/v1/request-logs?min_duration_ms=500');
        $responseSlow->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.duration_ms', 800);
    }

    public function test_super_admin_can_view_all_request_logs_and_filter_by_tenant(): void
    {
        ApiRequestLog::create([
            'tenant_id' => $this->tenantA->id,
            'method' => 'GET',
            'path' => 'api/v1/alpha',
            'status_code' => 200,
        ]);

        ApiRequestLog::create([
            'tenant_id' => $this->tenantB->id,
            'method' => 'GET',
            'path' => 'api/v1/beta',
            'status_code' => 200,
        ]);

        // All logs
        $this->actingAs($this->superAdmin)
            ->getJson('/api/v1/request-logs')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        // Filter by tenant
        $this->actingAs($this->superAdmin)
            ->getJson('/api/v1/request-logs?tenant_id=' . $this->tenantB->id)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.path', 'api/v1/beta');

        // Filter with tenant_id=0 (OpenAPI default parameter) should not filter out all logs
        $resZero = $this->actingAs($this->superAdmin)
            ->getJson('/api/v1/request-logs?tenant_id=0')
            ->assertOk();
        $this->assertGreaterThanOrEqual(2, $resZero->json('meta.total'));
    }

    public function test_request_logs_stats_endpoint(): void
    {
        ApiRequestLog::create([
            'tenant_id' => $this->tenantA->id,
            'status_code' => 200,
            'duration_ms' => 50,
        ]);

        ApiRequestLog::create([
            'tenant_id' => $this->tenantA->id,
            'status_code' => 500,
            'duration_ms' => 600,
        ]);

        $response = $this->actingAs($this->adminUserA)
            ->getJson('/api/v1/request-logs/stats');

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.total_requests', 2)
            ->assertJsonPath('data.avg_duration_ms', 325)
            ->assertJsonPath('data.status_distribution.2xx', 1)
            ->assertJsonPath('data.status_distribution.5xx', 1)
            ->assertJsonPath('data.slow_requests_count', 1);
    }

    public function test_show_request_log_details_and_tenant_isolation(): void
    {
        $logA = ApiRequestLog::create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $this->tenantA->id,
            'method' => 'POST',
            'path' => 'api/v1/auth/login',
            'status_code' => 200,
            'request_data' => [
                'email' => 'user@alpha.com',
                'password' => 'plaintext_secret_password',
            ],
        ]);

        // Tenant A admin can view and sensitive data is redacted
        $responseA = $this->actingAs($this->adminUserA)
            ->getJson('/api/v1/request-logs/' . $logA->id);
        $responseA->assertOk()
            ->assertJsonPath('data.uuid', $logA->uuid)
            ->assertJsonPath('data.request_data.email', 'user@alpha.com')
            ->assertJsonPath('data.request_data.password', '[REDACTED]');

        // Tenant B admin CANNOT view Tenant A's log (404)
        $this->actingAs($this->adminUserB)
            ->getJson('/api/v1/request-logs/' . $logA->id)
            ->assertNotFound();
    }
}

