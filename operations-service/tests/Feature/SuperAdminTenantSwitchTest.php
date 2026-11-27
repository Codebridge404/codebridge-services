<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
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
            ->get('/api/v1/_test/ops-tenant-switch', function (Request $request) {
                return response()->json([
                    'status' => 'success',
                    'user_tenant_id' => $request->user()?->tenant_id,
                    'auth_tenant_id' => $request->attributes->get('auth_tenant_id'),
                    'target_tenant_id' => $request->attributes->get('target_tenant_id'),
                ]);
            });
    }

    public function test_super_admin_can_switch_tenant_context_and_records_audit_log(): void
    {
        $superAdmin = User::factory()->make([
            'role' => 'super_admin',
            'tenant_id' => null,
        ]);
        $superAdmin->id = 901;

        Sanctum::actingAs($superAdmin, ['*']);

        $this->withHeader('X-Target-Tenant-ID', '42')
            ->getJson('/api/v1/_test/ops-tenant-switch')
            ->assertOk()
            ->assertHeader('X-Active-Tenant-ID', '42')
            ->assertJsonPath('user_tenant_id', '42')
            ->assertJsonPath('auth_tenant_id', 42)
            ->assertJsonPath('target_tenant_id', 42);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => 901,
            'action' => 'super_admin.tenant_context_switched',
            'auditable_type' => 'tenant',
            'auditable_id' => '42',
        ]);
    }

    public function test_non_super_admin_receives_403_on_x_target_tenant_id(): void
    {
        $manager = User::factory()->make([
            'role' => 'outlet_manager',
            'tenant_id' => 10,
        ]);
        $manager->id = 902;

        Sanctum::actingAs($manager, ['*']);

        $this->withHeader('X-Target-Tenant-ID', '42')
            ->getJson('/api/v1/_test/ops-tenant-switch')
            ->assertStatus(403)
            ->assertJsonPath('code', 'FORBIDDEN_TENANT_SWITCH');
    }

    public function test_invalid_target_tenant_id_returns_422(): void
    {
        $superAdmin = User::factory()->make([
            'role' => 'super_admin',
            'tenant_id' => null,
        ]);
        $superAdmin->id = 903;

        Sanctum::actingAs($superAdmin, ['*']);

        $this->withHeader('X-Target-Tenant-ID', 'abc')
            ->getJson('/api/v1/_test/ops-tenant-switch')
            ->assertStatus(422)
            ->assertJsonPath('code', 'INVALID_TARGET_TENANT_ID');
    }
}
