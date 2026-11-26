<?php

namespace Tests\Feature;

use App\Models\Auth\User;
use App\Models\Organization\Tenant;
use App\Services\ModuleEntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ModuleEntitlementMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['api', 'auth:sanctum', 'module:crm'])
            ->get('/api/v1/_test/module-crm', fn () => response()->json(['status' => 'success', 'ok' => true]));

        Route::middleware(['api', 'auth:sanctum', 'module:pos'])
            ->get('/api/v1/_test/module-pos', fn () => response()->json(['status' => 'success', 'ok' => true]));
    }

    public function test_global_kill_switch_returns_503_even_for_subscribed_tenant_and_super_admin(): void
    {
        DB::table('platform_modules')->insert([
            'id' => 'crm',
            'name' => 'CRM',
            'category' => 'growth',
            'description' => 'CRM Module',
            'icon' => 'users',
            'href' => '/crm',
            'is_core' => false,
            'is_active' => true,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $tenant = Tenant::create(['name' => 'Subscribed Cafe', 'slug' => 'sub-cafe-mod']);
        $admin = User::factory()->inWorkspace()->create(['role' => 'admin', 'tenant_id' => $tenant->id]);
        $superAdmin = User::factory()->create(['role' => 'super_admin']);

        DB::table('tenant_modules')->insert([
            'tenant_id' => $tenant->id,
            'module_id' => 'crm',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Initially allowed
        Sanctum::actingAs($admin, ['*']);
        $this->getJson('/api/v1/_test/module-crm')->assertOk();

        // Super admin activates global kill-switch via PlatformModuleController::update
        Sanctum::actingAs($superAdmin, ['*']);
        $this->putJson('/api/v1/super-admin/platform-modules/crm', ['is_active' => false])
            ->assertOk();

        // Subscribed tenant receives 503 MODULE_DISABLED
        Sanctum::actingAs($admin, ['*']);
        $this->getJson('/api/v1/_test/module-crm')
            ->assertStatus(503)
            ->assertJsonPath('code', 'MODULE_DISABLED');

        // Global kill-switch also returns 503 for super_admin
        Sanctum::actingAs($superAdmin, ['*']);
        $this->getJson('/api/v1/_test/module-crm')
            ->assertStatus(503)
            ->assertJsonPath('code', 'MODULE_DISABLED');
    }

    public function test_tenant_without_subscription_receives_403_module_not_subscribed(): void
    {
        DB::table('platform_modules')->insert([
            [
                'id' => 'crm',
                'name' => 'CRM',
                'category' => 'growth',
                'description' => 'CRM Module',
                'icon' => 'users',
                'href' => '/crm',
                'is_core' => false,
                'is_active' => true,
                'sort_order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 'pos',
                'name' => 'POS',
                'category' => 'core',
                'description' => 'Core POS',
                'icon' => 'store',
                'href' => '/pos',
                'is_core' => true,
                'is_active' => true,
                'sort_order' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $tenant = Tenant::create(['name' => 'Unsubscribed Cafe', 'slug' => 'unsub-cafe-mod']);
        $admin = User::factory()->inWorkspace()->create(['role' => 'admin', 'tenant_id' => $tenant->id]);

        Sanctum::actingAs($admin, ['*']);

        // Non-core unsubscribed module -> 403 MODULE_NOT_SUBSCRIBED
        $this->getJson('/api/v1/_test/module-crm')
            ->assertStatus(403)
            ->assertJsonPath('code', 'MODULE_NOT_SUBSCRIBED');

        // Core active module -> 200 OK
        $this->getJson('/api/v1/_test/module-pos')->assertOk();

        // Cache populated
        $this->assertNotNull(Cache::get(ModuleEntitlementService::GLOBAL_CACHE_KEY));
    }

    public function test_authorize_context_exposes_enabled_and_disabled_modules_for_operations_service(): void
    {
        DB::table('platform_modules')->insert([
            [
                'id' => 'crm',
                'name' => 'CRM',
                'category' => 'growth',
                'description' => 'CRM',
                'icon' => 'users',
                'href' => '/crm',
                'is_core' => false,
                'is_active' => false,
                'sort_order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 'inventory',
                'name' => 'Inventory',
                'category' => 'operations',
                'description' => 'Inventory',
                'icon' => 'box',
                'href' => '/inventory',
                'is_core' => false,
                'is_active' => true,
                'sort_order' => 2,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $tenant = Tenant::create(['name' => 'Context Cafe', 'slug' => 'ctx-cafe-mod']);
        $admin = User::factory()->inWorkspace()->create(['role' => 'admin', 'tenant_id' => $tenant->id]);
        DB::table('tenant_modules')->insert([
            'tenant_id' => $tenant->id,
            'module_id' => 'inventory',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($admin, ['*']);
        $this->getJson('/api/v1/auth/authorize-context')
            ->assertOk()
            ->assertJsonPath('data.enabled_modules.0', 'inventory')
            ->assertJsonPath('data.disabled_modules.0', 'crm');
    }
}
