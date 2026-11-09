<?php

namespace Tests\Feature\Performance;

use App\Models\Product;
use App\Models\TenantOperationSetting;
use App\Models\User;
use App\Services\AuthServiceClient;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StepThreeCachingTest extends TestCase
{
    use RefreshDatabase;

    private function mintJwt(int $userId, int $tenantId, array $overrides = []): string
    {
        $now = time();
        $payload = array_merge([
            'iss' => config('jwt.issuer', 'codebridge-auth-service'),
            'aud' => config('jwt.audience', 'codebridge-api'),
            'sub' => (string) $userId,
            'tenant_id' => $tenantId,
            'role' => 'admin',
            'iat' => $now,
            'exp' => $now + 900,
            'token_use' => 'access',
            'jti' => 'jwt-' . uniqid('', true),
            'sid' => 'sid-' . uniqid('', true),
            'session_version' => 1,
        ], $overrides);

        return JWT::encode($payload, config('jwt.secret', 'fallback-secret-for-dev-only'), 'HS256');
    }

    public function test_auth_service_client_caches_positive_context_honors_revocation_and_never_caches_failures(): void
    {
        Config::set('services.auth.url', 'https://auth.internal.test/api/v1');
        Config::set('services.auth.app_key', 'app-key-A');
        Config::set('services.auth.context_cache_ttl', 30);

        $token = $this->mintJwt(44, 101, [
            'jti' => 'jti-cache-44',
            'sid' => 'sid-cache-44',
            'session_version' => 1,
        ]);

        Http::fake([
            'https://auth.internal.test/api/v1/auth/authorize-context' => Http::sequence()
                ->push([
                    'authorized' => true,
                    'data' => [
                        'user_id' => 44,
                        'tenant_id' => 101,
                        'role' => 'admin',
                        'roles' => ['admin'],
                    ],
                ], 200)
                ->push([
                    'authorized' => false,
                    'code' => 'TENANT_CONTEXT_MISMATCH',
                    'message' => 'Denied for 202.',
                ], 403)
                ->push([
                    'authorized' => true,
                    'data' => [
                        'user_id' => 44,
                        'tenant_id' => 101,
                        'role' => 'admin',
                        'roles' => ['admin'],
                    ],
                ], 200)
                ->push([
                    'authorized' => true,
                    'data' => [
                        'user_id' => 44,
                        'tenant_id' => 101,
                        'role' => 'admin',
                        'roles' => ['admin'],
                    ],
                ], 200)
                ->push(['message' => 'Upstream down'], 500)
                ->push([
                    'authorized' => true,
                    'data' => [
                        'user_id' => 44,
                        'tenant_id' => 101,
                        'role' => 'admin',
                        'roles' => ['admin'],
                    ],
                ], 200),
        ]);

        $client = new AuthServiceClient();

        // 1. Two identical positive calls hit Auth only once
        $first = $client->authorizeContext($token, '101', 'app-key-A');
        $second = $client->authorizeContext($token, '101', 'app-key-A');
        $this->assertTrue($first['authorized']);
        $this->assertSame($first, $second);
        Http::assertSentCount(1);

        // 2. Different tenant scope misses positive cache -> consumes 403 -> NEVER cached
        $denied = $client->authorizeContext($token, '202', 'app-key-A');
        $this->assertFalse($denied['authorized']);
        $this->assertSame(403, $denied['status']);
        Http::assertSentCount(2);

        // 3. Changing X-App-Key isolates cache key cleanly
        $altApp = $client->authorizeContext($token, '101', 'app-key-B');
        $this->assertTrue($altApp['authorized']);
        Http::assertSentCount(3);

        // 4. Revoking sid immediately invalidates cached context even before TTL expires
        Cache::put('revoked_sid:sid-cache-44', 1, 60);
        $afterSidRevoke = $client->authorizeContext($token, '101', 'app-key-A');
        $this->assertFalse($afterSidRevoke['authorized']);
        $this->assertSame(401, $afterSidRevoke['status']);
        $this->assertSame('SESSION_REVOKED', $afterSidRevoke['code']);
        Http::assertSentCount(3); // Served denial directly without unnecessary upstream hit

        // 5. Warm staleVersionToken while version=1, then bump session_version:44 -> cache must deny immediately
        Cache::forget('revoked_sid:sid-cache-44');
        $staleVersionToken = $this->mintJwt(44, 101, [
            'jti' => 'jti-stale-44',
            'sid' => 'sid-stale-44',
            'session_version' => 1,
        ]);
        $warmStale = $client->authorizeContext($staleVersionToken, '101', 'app-key-A');
        $this->assertTrue($warmStale['authorized']);
        Http::assertSentCount(4);

        Cache::put('session_version:44', 2, 60);
        $versionDenied = $client->authorizeContext($staleVersionToken, '101', 'app-key-A');
        $this->assertFalse($versionDenied['authorized']);
        $this->assertSame('SESSION_SUPERSEDED', $versionDenied['code']);
        Http::assertSentCount(4);

        $outageToken = $this->mintJwt(44, 101, [
            'jti' => 'jti-outage-44',
            'sid' => 'sid-outage-44',
            'session_version' => 2,
        ]);

        // 6. Outage returns null (fail-closed by middleware) and must NOT cache negative state
        $this->assertNull($client->authorizeContext($outageToken, '101', 'app-key-A'));
        Http::assertSentCount(5);

        // Subsequent retry with same outageToken hits upstream again and recovers
        $recovered = $client->authorizeContext($outageToken, '101', 'app-key-A');
        $this->assertTrue($recovered['authorized']);
        Http::assertSentCount(6);
    }

    public function test_catalog_caching_isolates_tenants_and_public_visibility_and_invalidates_on_mutations(): void
    {
        $outletAId = DB::table('outlets')->insertGetId([
            'tenant_id' => 'tenant-101',
            'name' => 'Branch A',
            'code' => 'BR-A',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $catId = DB::table('categories')->insertGetId([
            'tenant_id' => 'tenant-101',
            'name' => 'Coffee',
            'slug' => 'coffee-101',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $brandId = DB::table('brands')->insertGetId([
            'tenant_id' => 'tenant-101',
            'name' => 'RoastCo',
            'slug' => 'roastco-101',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $prodA = Product::create([
            'tenant_id' => 'tenant-101',
            'category_id' => $catId,
            'brand_id' => $brandId,
            'name' => 'Tenant A Espresso',
            'sku' => 'TA-ESP-1',
            'selling_price' => 4.50,
            'cost_price' => 1.25,
            'is_active' => true,
        ]);

        $prodB = Product::create([
            'tenant_id' => 'tenant-202',
            'name' => 'Tenant B Secret Bag',
            'sku' => 'TB-SEC-1',
            'selling_price' => 99.00,
            'cost_price' => 40.00,
            'is_active' => true,
        ]);

        Product::create([
            'tenant_id' => null,
            'name' => 'Public Global Water',
            'sku' => 'PUB-H2O',
            'selling_price' => 1.00,
            'cost_price' => 0.30,
            'is_active' => true,
        ]);

        $staffA = User::factory()->create(['role' => 'admin']);
        $staffA->tenant_id = 'tenant-101';

        // Warm staff cache and verify zero DB queries on second identical read
        Sanctum::actingAs($staffA, ['*']);
        $warmRes = $this->getJson("/api/v1/products?outlet_id={$outletAId}")->assertOk();
        $warmSkus = array_column($warmRes->json('data'), 'sku');
        $this->assertContains('TA-ESP-1', $warmSkus);
        $this->assertNotContains('TB-SEC-1', $warmSkus);
        $this->assertArrayHasKey('cost_price', $warmRes->json('data.0'));

        DB::flushQueryLog();
        DB::enableQueryLog();
        $cachedRes = $this->getJson("/api/v1/products?outlet_id={$outletAId}")->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertSame($warmRes->json('data'), $cachedRes->json('data'));
        $productSelectQueries = array_filter($queries, fn ($q) => str_contains(strtolower($q['query']), 'from "products"') || str_contains(strtolower($q['query']), 'from `products`'));
        $this->assertCount(0, $productSelectQueries, 'Second cached products request must not query products table.');

        // Unauthenticated visitor must NOT reuse staff cache (never see cost_price or tenant-A private SKU)
        auth()->forgetGuards();
        app('auth')->forgetGuards();
        $this->app['auth']->forgetGuards();
        $publicRes = $this->getJson("/api/v1/products?outlet_id={$outletAId}")->assertOk();
        $publicSkus = array_column($publicRes->json('data'), 'sku');
        $this->assertContains('PUB-H2O', $publicSkus);
        $this->assertNotContains('TA-ESP-1', $publicSkus);
        foreach ($publicRes->json('data') as $row) {
            $this->assertArrayNotHasKey('cost_price', $row);
        }

        // Write via ProductController immediately busts Tenant A cache
        Sanctum::actingAs($staffA, ['*']);
        $this->putJson("/api/v1/products/{$prodA->id}", [
            'name' => 'Tenant A Espresso Updated',
            'selling_price' => 5.25,
        ])->assertOk();

        $afterUpdate = $this->getJson("/api/v1/products?outlet_id={$outletAId}")->assertOk();
        $this->assertSame('Tenant A Espresso Updated', $afterUpdate->json('data.0.name'));

        // Category/Brand/Discount writes invalidate their caches cleanly
        $this->getJson('/api/v1/categories')->assertOk();
        $this->postJson('/api/v1/categories', ['name' => 'Pastries'])->assertStatus(201);
        $catNames = array_column($this->getJson('/api/v1/categories')->assertOk()->json('data'), 'name');
        $this->assertContains('Pastries', $catNames);

        $this->getJson('/api/v1/brands')->assertOk();
        $this->postJson('/api/v1/brands', ['name' => 'BeanCraft'])->assertStatus(201);
        $brandNames = array_column($this->getJson('/api/v1/brands')->assertOk()->json('data'), 'name');
        $this->assertContains('BeanCraft', $brandNames);

        $this->getJson('/api/v1/discounts')->assertOk();
        $discountReq = \Illuminate\Http\Request::create('/api/v1/discounts', 'POST', [
            'name' => 'VIP 15%',
            'discount_pct' => '15%',
        ]);
        $discountReq->setUserResolver(fn () => $staffA);
        $discountRes = app(\App\Http\Controllers\Api\V1\DiscountController::class)->store($discountReq);
        $this->assertSame(201, $discountRes->getStatusCode());
        $discountNames = array_column($this->getJson('/api/v1/discounts')->assertOk()->json('data'), 'name');
        $this->assertContains('VIP 15%', $discountNames);

        // Confirm Tenant B cache unaffected by Tenant A writes
        $staffB = User::factory()->create(['role' => 'admin']);
        $staffB->tenant_id = 'tenant-202';
        Sanctum::actingAs($staffB, ['*']);
        $resB = $this->getJson("/api/v1/products?outlet_id={$outletAId}")->assertOk();
        $skusB = array_column($resB->json('data'), 'sku');
        $this->assertSame(['TB-SEC-1'], $skusB);
        $this->assertNotEmpty($prodB->id);
    }

    public function test_stock_mutations_invalidate_product_catalog_cache_and_operation_setting_cache(): void
    {
        $outletId = DB::table('outlets')->insertGetId([
            'tenant_id' => 'tenant-303',
            'name' => 'Main Cafe',
            'code' => 'MAIN-303',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $registerId = DB::table('registers')->insertGetId([
            'tenant_id' => 'tenant-303',
            'outlet_id' => $outletId,
            'name' => 'POS 1',
            'code' => 'REG-303',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $product = Product::create([
            'tenant_id' => 'tenant-303',
            'name' => 'Iced Latte',
            'sku' => 'LAT-303',
            'selling_price' => 3.50,
            'cost_price' => 1.00,
            'is_active' => true,
        ]);

        DB::table('inventory_balances')->insert([
            'tenant_id' => 'tenant-303',
            'outlet_id' => $outletId,
            'product_id' => $product->id,
            'on_hand' => 20,
            'reserved' => 0,
            'available' => 20,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $admin = User::factory()->create(['role' => 'admin']);
        $admin->tenant_id = 'tenant-303';
        Sanctum::actingAs($admin, ['*']);

        // 1. Warm catalog cache -> sees stock_on_hand = 20
        $initial = $this->getJson("/api/v1/products?outlet_id={$outletId}")->assertOk();
        $this->assertEquals(20, $initial->json('data.0.stock_on_hand'));

        // 2. Finalize POS checkout for 3 units -> CheckoutService must invalidate products cache
        $this->postJson('/api/v1/sales', [
            'outlet_id' => $outletId,
            'register_id' => $registerId,
            'idempotency_key' => 'idem-step3-stock-1',
            'tender_type' => 'cash',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 3],
            ],
        ])->assertStatus(201);

        $afterSale = $this->getJson("/api/v1/products?outlet_id={$outletId}")->assertOk();
        $this->assertEquals(17, $afterSale->json('data.0.stock_on_hand'));

        // 3. Adjust stock up by 5 units -> InventoryController must invalidate products cache
        $this->postJson('/api/v1/inventory/adjust', [
            'outlet_id' => $outletId,
            'product_id' => $product->id,
            'quantity' => 5,
            'type' => 'increment',
            'reason' => 'found',
        ])->assertOk();

        $afterAdjust = $this->getJson("/api/v1/products?outlet_id={$outletId}")->assertOk();
        $this->assertEquals(22, $afterAdjust->json('data.0.stock_on_hand'));

        // 4. TenantOperationSetting caches per tenant and invalidates on save
        $setting = TenantOperationSetting::create([
            'tenant_id' => 'tenant-303',
            'default_tax_percent' => 10.0000,
        ]);

        $loaded = TenantOperationSetting::getForTenant('tenant-303');
        $this->assertNotNull($loaded);
        $this->assertEquals(10.0, (float) $loaded->default_tax_percent);

        $setting->update(['default_tax_percent' => 12.5000]);
        $reloaded = TenantOperationSetting::getForTenant('tenant-303');
        $this->assertEquals(12.5, (float) $reloaded->default_tax_percent);
    }
}
