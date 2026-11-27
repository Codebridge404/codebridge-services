<?php

namespace Tests\Feature;

use App\Models\Auth\User;
use App\Models\Organization\Tenant;
use App\Services\JwtService;
use App\Services\SharedSecurityStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SharedSecurityStateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_tenant_suspension_publishes_shared_state_records_audit_log_and_blocks_jwt(): void
    {
        $tenant = Tenant::create([
            'name' => 'Coffee Shop',
            'slug' => 'coffee-shop-sec',
            'status' => 'active',
        ]);

        $superAdmin = User::factory()->create(['role' => 'super_admin']);
        $member = User::factory()->inWorkspace()->create([
            'role' => 'admin',
            'tenant_id' => $tenant->id,
            'is_active' => true,
        ]);

        $jwtService = app(JwtService::class);
        $token = $jwtService->generateTokenForUser($member);
        $superAdminToken = $jwtService->generateTokenForUser($superAdmin);

        // Active tenant succeeds
        $this->withToken($token)
            ->getJson('/api/v1/auth/authorize-context')
            ->assertOk();

        // Super admin suspends Coffee Shop
        $this->withToken($superAdminToken)
            ->postJson("/api/v1/super-admin/tenants/{$tenant->id}/suspend")
            ->assertOk()
            ->assertJsonPath('status', 'suspended');

        $this->assertSame('suspended', Cache::get(SharedSecurityStateService::TENANT_STATUS_PREFIX.$tenant->id));
        $this->assertSame(1, Cache::get(SharedSecurityStateService::TENANT_STATUS_VERSION_PREFIX.$tenant->id));

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'tenant.status_changed',
            'module' => 'tenants',
            'tenant_id' => $tenant->id,
        ]);

        // Suspended tenant JWT is immediately blocked with 403 TENANT_SUSPENDED
        $this->withToken($token)
            ->getJson('/api/v1/auth/authorize-context')
            ->assertStatus(403)
            ->assertJsonPath('code', 'TENANT_SUSPENDED');

        // Reactivate tenant -> version increments and access resumes
        $this->withToken($superAdminToken)
            ->postJson("/api/v1/super-admin/tenants/{$tenant->id}/suspend")
            ->assertOk()
            ->assertJsonPath('status', 'active');

        $this->assertSame('active', Cache::get(SharedSecurityStateService::TENANT_STATUS_PREFIX.$tenant->id));
        $this->assertSame(2, Cache::get(SharedSecurityStateService::TENANT_STATUS_VERSION_PREFIX.$tenant->id));

        $this->withToken($token)
            ->getJson('/api/v1/auth/authorize-context')
            ->assertOk();
    }
}
