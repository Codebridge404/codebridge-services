<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ModuleEntitlementService;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ModuleEntitlementMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_global_kill_switch_in_shared_cache_returns_503_even_for_subscribed_tenant_and_super_admin(): void
    {
        Cache::put(ModuleEntitlementService::GLOBAL_CACHE_KEY, [
            'inventory' => ['is_active' => false, 'is_core' => false],
            'finance' => ['is_active' => true, 'is_core' => false],
        ], 300);

        Cache::put(ModuleEntitlementService::TENANT_CACHE_PREFIX.'10', ['inventory', 'finance'], 300);

        $manager = User::factory()->make([
            'role' => 'outlet_manager',
            'tenant_id' => 10,
        ]);
        $manager->id = 101;
        $superAdmin = User::factory()->make([
            'role' => 'super_admin',
            'tenant_id' => 10,
        ]);
        $superAdmin->id = 102;

        Sanctum::actingAs($manager, ['*']);
        $this->getJson('/api/v1/inventory/balances')
            ->assertStatus(503)
            ->assertJsonPath('code', 'MODULE_DISABLED');

        // Subscribed active module still succeeds
        $this->getJson('/api/v1/expenses')->assertOk();

        // Global kill-switch also blocks super_admin
        Sanctum::actingAs($superAdmin, ['*']);
        $this->getJson('/api/v1/inventory/balances')
            ->assertStatus(503)
            ->assertJsonPath('code', 'MODULE_DISABLED');
    }

    public function test_tenant_without_subscription_receives_403_and_aliases_match(): void
    {
        Cache::put(ModuleEntitlementService::GLOBAL_CACHE_KEY, [
            'inventory-suite' => ['is_active' => true, 'is_core' => false],
            'financial-management' => ['is_active' => true, 'is_core' => false],
        ], 300);

        // Tenant 22 only has financial-management (alias for finance), not inventory
        Cache::put(ModuleEntitlementService::TENANT_CACHE_PREFIX.'22', ['financial-management'], 300);

        $manager = User::factory()->make([
            'role' => 'outlet_manager',
            'tenant_id' => 22,
        ]);
        $manager->id = 103;

        Sanctum::actingAs($manager, ['*']);

        $this->getJson('/api/v1/inventory/balances')
            ->assertStatus(403)
            ->assertJsonPath('code', 'MODULE_NOT_SUBSCRIBED');

        // Alias 'financial-management' satisfies 'module:finance'
        $this->getJson('/api/v1/expenses')->assertOk();
    }

    public function test_core_module_is_allowed_when_globally_active(): void
    {
        Cache::put(ModuleEntitlementService::GLOBAL_CACHE_KEY, [
            'inventory' => ['is_active' => true, 'is_core' => true],
        ], 300);
        Cache::put(ModuleEntitlementService::TENANT_CACHE_PREFIX.'33', [], 300);

        $manager = User::factory()->make([
            'role' => 'outlet_manager',
            'tenant_id' => 33,
        ]);
        $manager->id = 104;

        Sanctum::actingAs($manager, ['*']);
        $this->getJson('/api/v1/inventory/balances')->assertOk();
    }

    public function test_authorize_context_enabled_and_disabled_modules_enforced_over_jwt_contract(): void
    {
        config([
            'services.auth.verify_via_http' => true,
            'services.auth.url' => 'http://auth-service.test/api/v1',
        ]);

        $secret = (string) config('jwt.secret');
        $token = JWT::encode([
            'iss' => config('jwt.issuer', 'codebridge-auth-service'),
            'aud' => config('jwt.audience', 'codebridge-api'),
            'sub' => '501',
            'name' => 'Manager',
            'email' => 'manager@sunnycafe.kh',
            'role' => 'outlet_manager',
            'tenant_id' => '44',
            'token_use' => 'access',
            'iat' => time(),
            'exp' => time() + 3600,
        ], $secret, 'HS256');

        Http::fake([
            'http://auth-service.test/api/v1/auth/authorize-context' => Http::response([
                'authorized' => true,
                'data' => [
                    'user_id' => 501,
                    'tenant_id' => 44,
                    'role' => 'outlet_manager',
                    'roles' => ['outlet_manager'],
                    'is_super_admin' => false,
                    'is_platform_super_admin' => false,
                    'enabled_modules' => ['pos'],
                    'disabled_modules' => ['finance'],
                ],
            ], 200),
        ]);

        // Kill-switched in authorize-context -> 503 MODULE_DISABLED
        $this->withToken($token)
            ->getJson('/api/v1/expenses')
            ->assertStatus(503)
            ->assertJsonPath('code', 'MODULE_DISABLED');

        // Not in enabled_modules -> 403 MODULE_NOT_SUBSCRIBED
        $this->withToken($token)
            ->getJson('/api/v1/inventory/balances')
            ->assertStatus(403)
            ->assertJsonPath('code', 'MODULE_NOT_SUBSCRIBED');
    }
}
