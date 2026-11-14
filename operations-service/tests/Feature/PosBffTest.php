<?php

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PosBffTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsTenantCashier(int $tenantId, int $outletId, string $role = 'cashier'): array
    {
        $user = User::factory()->create([
            'role' => $role,
            'outlet_id' => $outletId,
        ]);

        $secret = config('jwt.secret', 'codebridge_enterprise_stateless_jwt_secret_key_2026_secured');
        $token = JWT::encode([
            'iss' => 'codebridge-auth-service',
            'aud' => config('jwt.audience', 'codebridge-api'),
            'sub' => (string) $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $role,
            'token_use' => 'access',
            'tenant_id' => (string) $tenantId,
            'outlet_id' => (string) $outletId,
            'iat' => time(),
            'exp' => time() + 3600,
        ], $secret, 'HS256');

        return [
            'user' => $user,
            'headers' => ['Authorization' => "Bearer {$token}"],
        ];
    }

    private function seedTenantFixture(int $tenantId, string $suffix): array
    {
        $outletId = DB::table('outlets')->insertGetId([
            'tenant_id' => $tenantId,
            'name' => "Branch {$suffix}",
            'code' => "BR-{$suffix}-{$tenantId}",
            'address' => "Phnom Penh {$suffix}",
            'phone' => '012345678',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $registerId = DB::table('registers')->insertGetId([
            'tenant_id' => $tenantId,
            'outlet_id' => $outletId,
            'name' => "Register {$suffix}",
            'code' => "REG-{$suffix}-1",
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $categoryId = DB::table('categories')->insertGetId([
            'tenant_id' => $tenantId,
            'name' => "Drinks {$suffix}",
            'slug' => "drinks-{$suffix}-{$tenantId}",
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $productId = DB::table('products')->insertGetId([
            'tenant_id' => $tenantId,
            'category_id' => $categoryId,
            'name' => "Iced Latte {$suffix}",
            'sku' => "LATTE-{$suffix}-{$tenantId}",
            'barcode' => "885000{$tenantId}01",
            'selling_price' => 3.50,
            'cost_price' => 1.20,
            'min_reorder_point' => 5,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('inventory_balances')->insert([
            'tenant_id' => $tenantId,
            'outlet_id' => $outletId,
            'product_id' => $productId,
            'on_hand' => 25,
            'reserved' => 0,
            'available' => 25,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('outletId', 'registerId', 'categoryId', 'productId');
    }

    public function test_pos_bff_endpoints_require_authentication(): void
    {
        $this->getJson('/api/v1/pos-bff/bootstrap')->assertStatus(401);
        $this->getJson('/api/v1/pos-bff/catalog')->assertStatus(401);
        $this->getJson('/api/v1/pos-bff/shift-summary')->assertStatus(401);
        $this->postJson('/api/v1/pos-bff/checkout', [])->assertStatus(401);
    }

    public function test_bootstrap_aggregates_outlet_shift_rate_and_counters_for_tenant(): void
    {
        $fixture = $this->seedTenantFixture(101, 'A');
        ['user' => $cashier, 'headers' => $headers] = $this->actingAsTenantCashier(101, $fixture['outletId']);

        ExchangeRate::create([
            'tenant_id' => 101,
            'base_currency' => 'USD',
            'target_currency' => 'KHR',
            'rate' => 4110.0,
            'source' => 'NBC_LIVE',
            'effective_date' => now()->toDateString(),
            'is_active' => true,
            'synced_at' => now(),
        ]);

        $shiftId = DB::table('shifts')->insertGetId([
            'tenant_id' => 101,
            'outlet_id' => $fixture['outletId'],
            'register_id' => $fixture['registerId'],
            'user_id' => $cashier->id,
            'opened_at' => now(),
            'opening_float' => 100.00,
            'expected_cash' => 100.00,
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('cash_drawer_movements')->insert([
            'tenant_id' => 101,
            'shift_id' => $shiftId,
            'user_id' => $cashier->id,
            'type' => 'in',
            'amount' => 25.00,
            'reason' => 'Petty cash top-up',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Cache::put("pos_held_carts_tenant_101_outlet_{$fixture['outletId']}", [
            ['id' => 'HOLD-1', 'items' => []],
            ['id' => 'HOLD-2', 'items' => []],
        ], 300);

        $res = $this->withHeaders($headers)->getJson('/api/v1/pos-bff/bootstrap');
        $res->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.user.id', $cashier->id)
            ->assertJsonPath('data.outlet.id', $fixture['outletId'])
            ->assertJsonPath('data.active_shift.shift.id', $shiftId)
            ->assertJsonPath('data.active_shift.summary.expected_cash', 125)
            ->assertJsonPath('data.exchange_rate.active_rate', 4110)
            ->assertJsonPath('data.counters.held_carts_count', 2);
    }

    public function test_catalog_and_shift_summary_return_scoped_inventory_and_drawer_totals(): void
    {
        $fixtureA = $this->seedTenantFixture(101, 'A');
        $fixtureB = $this->seedTenantFixture(202, 'B');

        ['headers' => $headersA] = $this->actingAsTenantCashier(101, $fixtureA['outletId']);

        $shiftBId = DB::table('shifts')->insertGetId([
            'tenant_id' => 202,
            'outlet_id' => $fixtureB['outletId'],
            'register_id' => $fixtureB['registerId'],
            'user_id' => 999,
            'opened_at' => now(),
            'opening_float' => 50.00,
            'expected_cash' => 50.00,
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $catalogRes = $this->withHeaders($headersA)->getJson('/api/v1/pos-bff/catalog?search=Latte');
        $catalogRes->assertStatus(200)
            ->assertJsonCount(1, 'data.products')
            ->assertJsonPath('data.products.0.name', 'Iced Latte A')
            ->assertJsonPath('data.products.0.stock_on_hand', 25);

        // Tenant A must never read Tenant B's outlet or shift
        $this->withHeaders($headersA)->getJson("/api/v1/pos-bff/bootstrap?outlet_id={$fixtureB['outletId']}")->assertStatus(403);
        $this->withHeaders($headersA)->getJson("/api/v1/pos-bff/catalog?outlet_id={$fixtureB['outletId']}")->assertStatus(403);
        $this->withHeaders($headersA)->getJson("/api/v1/pos-bff/shift-summary?shift_id={$shiftBId}")->assertStatus(403);
    }

    public function test_pos_bff_checkout_supports_cash_and_idempotent_dynamic_khqr(): void
    {
        $fixture = $this->seedTenantFixture(101, 'A');
        ['user' => $cashier, 'headers' => $headers] = $this->actingAsTenantCashier(101, $fixture['outletId']);

        $shiftId = DB::table('shifts')->insertGetId([
            'tenant_id' => 101,
            'outlet_id' => $fixture['outletId'],
            'register_id' => $fixture['registerId'],
            'user_id' => $cashier->id,
            'opened_at' => now(),
            'opening_float' => 50.00,
            'expected_cash' => 50.00,
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 1. Cash checkout updates expected cash and decrements stock
        $cashRes = $this->withHeaders($headers)->postJson('/api/v1/pos-bff/checkout', [
            'outlet_id' => $fixture['outletId'],
            'register_id' => $fixture['registerId'],
            'shift_id' => $shiftId,
            'idempotency_key' => 'pos-bff-cash-001',
            'tender_type' => 'cash',
            'items' => [
                ['product_id' => $fixture['productId'], 'quantity' => 2],
            ],
        ]);

        $cashRes->assertStatus(201)
            ->assertJsonPath('data.is_duplicate', false)
            ->assertJsonPath('data.sale.grand_total', 7)
            ->assertJsonPath('data.khqr', null)
            ->assertJsonPath('data.active_shift.summary.cash_sales', 7)
            ->assertJsonPath('data.active_shift.summary.expected_cash', 57);

        // 2. KHQR checkout generates dynamic KHQR payload once and stays idempotent on retry
        $khqrPayload = [
            'outlet_id' => $fixture['outletId'],
            'register_id' => $fixture['registerId'],
            'shift_id' => $shiftId,
            'idempotency_key' => 'pos-bff-khqr-001',
            'tender_type' => 'khqr',
            'items' => [
                ['product_id' => $fixture['productId'], 'quantity' => 1],
            ],
        ];

        $khqrRes = $this->withHeaders($headers)->postJson('/api/v1/pos-bff/checkout', $khqrPayload);
        $khqrRes->assertStatus(201)
            ->assertJsonPath('data.is_duplicate', false)
            ->assertJsonPath('data.khqr.status', 'pending')
            ->assertJsonPath('data.khqr.currency', 'USD');

        $firstMd5 = $khqrRes->json('data.khqr.md5');
        $firstAttemptId = $khqrRes->json('data.khqr.attempt_id');
        $this->assertNotEmpty($firstMd5);
        $this->assertNotEmpty($khqrRes->json('data.khqr.khqr_string'));

        // Idempotent replay with identical key reuses existing sale and KHQR attempt
        $retryRes = $this->withHeaders($headers)->postJson('/api/v1/pos-bff/checkout', $khqrPayload);
        $retryRes->assertStatus(200)
            ->assertJsonPath('data.is_duplicate', true)
            ->assertJsonPath('data.khqr.md5', $firstMd5)
            ->assertJsonPath('data.khqr.attempt_id', $firstAttemptId);

        $this->assertSame(1, DB::table('payment_attempts')->where('id', $firstAttemptId)->count());
        $this->assertEquals(22, (float) DB::table('inventory_balances')->where('product_id', $fixture['productId'])->value('on_hand'));
    }

    public function test_cashier_merchant_override_is_ignored_and_uses_tenant_configured_bank_account(): void
    {
        $fixture = $this->seedTenantFixture(101, 'A');
        ['user' => $cashier, 'headers' => $headers] = $this->actingAsTenantCashier(101, $fixture['outletId']);

        // Configure authoritative tenant bank account
        DB::table('bank_accounts')->insert([
            'tenant_id' => 101,
            'bank_name' => 'Canadia Bank',
            'account_name' => 'Sunny Cafe Phnom Penh',
            'account_number' => 'sunnycafe@canadia',
            'currency' => 'USD',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Malicious cashier attempts to divert funds by sending their own personal Bakong ID
        $checkoutPayload = [
            'outlet_id' => $fixture['outletId'],
            'register_id' => $fixture['registerId'],
            'idempotency_key' => 'pos-bff-khqr-hijack-attempt-001',
            'tender_type' => 'khqr',
            'merchant_id' => 'attacker_cashier@bakong',
            'merchant_name' => 'Attacker Wallet',
            'merchant_city' => 'Phnom Penh',
            'bank_name' => 'Attacker Bank',
            'items' => [
                ['product_id' => $fixture['productId'], 'quantity' => 1],
            ],
        ];

        $res = $this->withHeaders($headers)->postJson('/api/v1/pos-bff/checkout', $checkoutPayload);

        $res->assertStatus(201)
            ->assertJsonPath('data.khqr.status', 'pending')
            ->assertJsonPath('data.khqr.merchant_name', 'Sunny Cafe Phnom Penh');

        $khqrString = (string) $res->json('data.khqr.khqr_string');
        $this->assertStringContainsString('sunnycafe@canadia', $khqrString);
        $this->assertStringNotContainsString('attacker_cashier@bakong', $khqrString);

        // Payment status in DB must be pending
        $saleId = $res->json('data.sale.id');
        $payment = DB::table('payments')->where('sale_id', $saleId)->first();
        $this->assertNotNull($payment);
        $this->assertSame('pending', $payment->status);
    }

    public function test_cross_tenant_bank_account_selection_fails_with_403(): void
    {
        $fixtureA = $this->seedTenantFixture(101, 'A');
        $fixtureB = $this->seedTenantFixture(202, 'B');
        ['user' => $cashierA, 'headers' => $headersA] = $this->actingAsTenantCashier(101, $fixtureA['outletId']);

        // Bank account belonging to Tenant 202
        $tenant202BankAccountId = DB::table('bank_accounts')->insertGetId([
            'tenant_id' => 202,
            'bank_name' => 'ABA Bank',
            'account_name' => 'Tenant B Store',
            'account_number' => 'tenantb@aba',
            'currency' => 'USD',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $checkoutPayload = [
            'outlet_id' => $fixtureA['outletId'],
            'register_id' => $fixtureA['registerId'],
            'idempotency_key' => 'pos-bff-khqr-cross-tenant-001',
            'tender_type' => 'khqr',
            'bank_account_id' => $tenant202BankAccountId,
            'items' => [
                ['product_id' => $fixtureA['productId'], 'quantity' => 1],
            ],
        ];

        $res = $this->withHeaders($headersA)->postJson('/api/v1/pos-bff/checkout', $checkoutPayload);
        $res->assertStatus(403)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'Unauthorized: Bank account belongs to another organization.');
    }

    public function test_khqr_checkout_with_disabled_qr_generation_leaves_payment_as_pending(): void
    {
        $fixture = $this->seedTenantFixture(101, 'A');
        ['user' => $cashier, 'headers' => $headers] = $this->actingAsTenantCashier(101, $fixture['outletId']);

        $shiftId = DB::table('shifts')->insertGetId([
            'tenant_id' => 101,
            'outlet_id' => $fixture['outletId'],
            'register_id' => $fixture['registerId'],
            'user_id' => $cashier->id,
            'opened_at' => now(),
            'opening_float' => 50.00,
            'expected_cash' => 50.00,
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = [
            'outlet_id' => $fixture['outletId'],
            'register_id' => $fixture['registerId'],
            'shift_id' => $shiftId,
            'idempotency_key' => 'pos-bff-khqr-no-gen-001',
            'tender_type' => 'khqr',
            'generate_khqr' => false,
            'items' => [
                ['product_id' => $fixture['productId'], 'quantity' => 1],
            ],
        ];

        $res = $this->withHeaders($headers)->postJson('/api/v1/pos-bff/checkout', $payload);
        $res->assertStatus(201)
            ->assertJsonPath('data.khqr', null);

        $saleId = $res->json('data.sale.id');
        $payment = DB::table('payments')->where('sale_id', $saleId)->first();
        $this->assertNotNull($payment);
        $this->assertSame('pending', $payment->status);

        // Shift summary must not count pending KHQR payment as paid sales
        $shiftSummaryRes = $this->withHeaders($headers)->getJson("/api/v1/pos-bff/shift-summary?shift_id={$shiftId}");
        $shiftSummaryRes->assertStatus(200)
            ->assertJsonPath('data.active_shift.summary.khqr_sales', 0)
            ->assertJsonPath('data.active_shift.summary.cash_sales', 0);
    }

    public function test_khqr_checkout_generation_failure_never_marks_payment_as_paid_and_retry_recovers(): void
    {
        $fixture = $this->seedTenantFixture(101, 'A');
        ['user' => $cashier, 'headers' => $headers] = $this->actingAsTenantCashier(101, $fixture['outletId']);

        // First attempt: pass bank_account_id that belongs to another tenant to trigger failure
        $foreignBankAccountId = DB::table('bank_accounts')->insertGetId([
            'tenant_id' => 999,
            'bank_name' => 'Foreign Bank',
            'account_name' => 'Foreign Tenant',
            'account_number' => 'foreign@bakong',
            'currency' => 'USD',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = [
            'outlet_id' => $fixture['outletId'],
            'register_id' => $fixture['registerId'],
            'idempotency_key' => 'pos-bff-khqr-fail-retry-001',
            'tender_type' => 'khqr',
            'bank_account_id' => $foreignBankAccountId,
            'items' => [
                ['product_id' => $fixture['productId'], 'quantity' => 1],
            ],
        ];

        // First attempt fails at authorization
        $failRes = $this->withHeaders($headers)->postJson('/api/v1/pos-bff/checkout', $payload);
        $failRes->assertStatus(403);

        // Verify sale was created by finalizeSale, but payment in DB is strictly pending, never paid
        $sale = DB::table('sales')->where('idempotency_key', 'pos-bff-khqr-fail-retry-001')->first();
        $this->assertNotNull($sale);
        $payment = DB::table('payments')->where('sale_id', $sale->id)->first();
        $this->assertNotNull($payment);
        $this->assertSame('pending', $payment->status);

        // Stock was decremented once (25 - 1 = 24)
        $this->assertEquals(24, (float) DB::table('inventory_balances')->where('product_id', $fixture['productId'])->value('on_hand'));

        // Retry with same idempotency key and valid tenant bank account
        $ownBankAccountId = DB::table('bank_accounts')->insertGetId([
            'tenant_id' => 101,
            'bank_name' => 'Bakong System',
            'account_name' => 'Valid Branch A',
            'account_number' => 'validbrancha@bakong',
            'currency' => 'USD',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload['bank_account_id'] = $ownBankAccountId;

        $retryRes = $this->withHeaders($headers)->postJson('/api/v1/pos-bff/checkout', $payload);
        $retryRes->assertStatus(200)
            ->assertJsonPath('data.is_duplicate', true)
            ->assertJsonPath('data.khqr.status', 'pending');

        // Retries produce no duplicate sale, no duplicate payment, and no duplicate stock deduction
        $this->assertSame(1, DB::table('sales')->where('idempotency_key', 'pos-bff-khqr-fail-retry-001')->count());
        $this->assertSame(1, DB::table('payments')->where('sale_id', $sale->id)->count());
        $this->assertSame(1, DB::table('payment_attempts')->where('payment_id', $payment->id)->count());
        $this->assertEquals(24, (float) DB::table('inventory_balances')->where('product_id', $fixture['productId'])->value('on_hand'));
    }
}
