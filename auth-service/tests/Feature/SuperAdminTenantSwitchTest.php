<?php

namespace Tests\Feature;

use App\Models\Auth\User;
use App\Models\Organization\Tenant;
use App\Services\TenantAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SuperAdminTenantSwitchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['api', 'auth:sanctum'])
            ->get('/api/v1/_test/tenant-switch-outlets', function (Request $request) {
                $rows = TenantAccess::scope(DB::table('outlets'), $request)
                    ->orderBy('id')
                    ->pluck('name')
                    ->all();

                return response()->json([
                    'status' => 'success',
                    'tenant_id' => $request->user()?->tenant_id,
                    'auth_tenant_id' => $request->attributes->get('auth_tenant_id'),
                    'outlets' => $rows,
                ]);
            });
    }

    public function test_super_admin_can_scope_queries_with_x_target_tenant_id_and_records_audit_log(): void
    {
        $tenantA = Tenant::create(['name' => 'Tenant Alpha', 'slug' => 'tenant-alpha-sw', 'uuid' => '550e8400-e29b-41d4-a716-446655440000']);
        $tenantB = Tenant::create(['name' => 'Tenant Beta', 'slug' => 'tenant-beta-sw']);

        DB::table('outlets')->insert([
            ['tenant_id' => $tenantA->id, 'name' => 'Alpha Main', 'code' => 'ALPHA-01', 'created_at' => now(), 'updated_at' => now()],
            ['tenant_id' => $tenantB->id, 'name' => 'Beta Main', 'code' => 'BETA-01', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $superAdmin = User::factory()->create(['role' => 'super_admin']);
        Sanctum::actingAs($superAdmin, ['*']);

        // Without X-Target-Tenant-ID -> unscoped (returns both)
        $this->getJson('/api/v1/_test/tenant-switch-outlets')
            ->assertOk()
            ->assertJsonPath('outlets', ['Alpha Main', 'Beta Main']);

        // With X-Target-Tenant-ID -> scoped to Tenant Beta + audit_logs recorded
        $this->withHeader('X-Target-Tenant-ID', (string) $tenantB->id)
            ->getJson('/api/v1/_test/tenant-switch-outlets')
            ->assertOk()
            ->assertHeader('X-Active-Tenant-ID', (string) $tenantB->id)
            ->assertJsonPath('tenant_id', $tenantB->id)
            ->assertJsonPath('auth_tenant_id', $tenantB->id)
            ->assertJsonPath('outlets', ['Beta Main']);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'super_admin.tenant_context_switched',
            'module' => 'super_admin',
            'user_id' => $superAdmin->id,
            'tenant_id' => $tenantB->id,
        ]);

        // Also supports UUID in X-Target-Tenant-ID
        $this->withHeader('X-Target-Tenant-ID', (string) $tenantA->uuid)
            ->getJson('/api/v1/_test/tenant-switch-outlets')
            ->assertOk()
            ->assertHeader('X-Active-Tenant-ID', (string) $tenantA->id)
            ->assertJsonPath('tenant_id', $tenantA->id)
            ->assertJsonPath('outlets', ['Alpha Main']);
    }

    public function test_non_super_admin_cannot_use_x_target_tenant_id(): void
    {
        $tenantA = Tenant::create(['name' => 'Tenant Alpha', 'slug' => 'tenant-alpha-forbid']);
        $tenantB = Tenant::create(['name' => 'Tenant Beta', 'slug' => 'tenant-beta-forbid']);
        $admin = User::factory()->inWorkspace()->create(['role' => 'admin', 'tenant_id' => $tenantA->id]);

        Sanctum::actingAs($admin, ['*']);

        $this->withHeader('X-Target-Tenant-ID', (string) $tenantB->id)
            ->getJson('/api/v1/_test/tenant-switch-outlets')
            ->assertStatus(403)
            ->assertJsonPath('code', 'FORBIDDEN_TENANT_SWITCH');

        $this->assertDatabaseHas('security_events', [
            'event_type' => 'unauthorized_tenant_switch',
            'rule_code' => 'SEC_FORBIDDEN_TENANT_SWITCH',
            'user_id' => $admin->id,
        ]);
    }

    public function test_invalid_or_missing_target_tenant_returns_422_or_404(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin']);
        Sanctum::actingAs($superAdmin, ['*']);

        $this->withHeader('X-Target-Tenant-ID', 'not-a-number')
            ->getJson('/api/v1/_test/tenant-switch-outlets')
            ->assertStatus(422)
            ->assertJsonPath('code', 'INVALID_TARGET_TENANT_ID');

        $this->withHeader('X-Target-Tenant-ID', '999999')
            ->getJson('/api/v1/_test/tenant-switch-outlets')
            ->assertStatus(404)
            ->assertJsonPath('code', 'TARGET_TENANT_NOT_FOUND');
    }
}
