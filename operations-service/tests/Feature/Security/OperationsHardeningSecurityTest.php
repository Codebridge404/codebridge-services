<?php

namespace Tests\Feature\Security;

use App\Models\Product;
use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OperationsHardeningSecurityTest extends TestCase
{
    use RefreshDatabase;

    private mixed $originalAuthDbConfig = null;
    private mixed $originalVerifyViaHttp = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalAuthDbConfig = config('database.connections.auth_db');
        $this->originalVerifyViaHttp = config('services.auth.verify_via_http', false);
    }

    protected function tearDown(): void
    {
        Config::set('services.auth.verify_via_http', $this->originalVerifyViaHttp);
        Config::set('database.connections.auth_db', $this->originalAuthDbConfig);
        DB::purge('auth_db');

        parent::tearDown();
    }

    private function signJwt(array $overrides = []): string
    {
        $secret = (string) config('jwt.secret', 'codebridge_enterprise_stateless_jwt_secret_key_2026_secured');
        $now = time();

        $payload = array_merge([
            'iss' => config('jwt.issuer', 'codebridge-auth-service'),
            'aud' => config('jwt.audience', 'codebridge-api'),
            'sub' => '10',
            'name' => 'Test User',
            'email' => 'test@sunnycafe.kh',
            'role' => 'admin',
            'token_use' => 'access',
            'tenant_id' => '1',
            'outlet_id' => '1',
            'jti' => (string) Str::uuid(),
            'iat' => $now,
            'exp' => $now + 900,
        ], $overrides);

        return JWT::encode($payload, $secret, 'HS256');
    }

    public function test_jwt_with_wrong_audience_or_missing_token_use_is_rejected(): void
    {
        $wrongAud = $this->signJwt(['aud' => 'wrong-service']);
        $this->withHeaders([
            'Authorization' => "Bearer {$wrongAud}",
        ])->getJson('/api/v1/currencies/exchange-rate/active')->assertStatus(401);

        $noTokenUse = $this->signJwt();
        $secret = (string) config('jwt.secret', 'codebridge_enterprise_stateless_jwt_secret_key_2026_secured');
        $payloadWithoutUse = [
            'iss' => config('jwt.issuer', 'codebridge-auth-service'),
            'aud' => config('jwt.audience', 'codebridge-api'),
            'sub' => '10',
            'role' => 'admin',
            'tenant_id' => '1',
            'iat' => time(),
            'exp' => time() + 900,
        ];
        $noUseToken = JWT::encode($payloadWithoutUse, $secret, 'HS256');

        $this->withHeaders([
            'Authorization' => "Bearer {$noUseToken}",
        ])->getJson('/api/v1/currencies/exchange-rate/active')->assertStatus(401);

        $refreshUse = $this->signJwt(['token_use' => 'refresh']);
        $this->withHeaders([
            'Authorization' => "Bearer {$refreshUse}",
        ])->getJson('/api/v1/currencies/exchange-rate/active')->assertStatus(401);
    }

    public function test_revoked_sid_session_in_auth_db_is_rejected_by_operations_jwt_middleware(): void
    {
        Config::set('database.connections.auth_db', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        DB::purge('auth_db');
        DB::reconnect('auth_db');
        Config::set('services.auth.verify_via_http', false);

        $schema = Schema::connection('auth_db');
        $schema->create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->boolean('is_active')->default(true);
            $table->timestamp('locked_until')->nullable();
        });
        $schema->create('user_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('uuid');
            $table->unsignedBigInteger('user_id');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('expires_at')->nullable();
        });

        DB::connection('auth_db')->table('users')->insert([
            'id' => 10,
            'name' => 'Revoked Cashier',
            'email' => 'revoked@sunnycafe.kh',
            'is_active' => true,
        ]);

        $revokedSid = (string) Str::uuid();
        DB::connection('auth_db')->table('user_sessions')->insert([
            'uuid' => $revokedSid,
            'user_id' => 10,
            'revoked_at' => now(),
            'expires_at' => now()->addHour(),
        ]);

        $jwt = $this->signJwt(['sub' => '10', 'sid' => $revokedSid]);
        $this->withHeaders([
            'Authorization' => "Bearer {$jwt}",
        ])->getJson('/api/v1/currencies/exchange-rate/active')
            ->assertStatus(401)
            ->assertJsonPath('code', 'SESSION_REVOKED');
    }

    public function test_payment_webhook_and_confirm_hash_reject_amount_tampering_and_simulation_disabled_in_prod(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $user->tenant_id = '101';
        Sanctum::actingAs($user, ['*']);

        $paymentId = DB::table('payments')->insertGetId([
            'tenant_id' => 101,
            'sale_id' => 0,
            'tender_type' => 'khqr',
            'amount' => 50.00,
            'currency' => 'USD',
            'status' => 'pending',
            'reference_number' => 'BILL-SEC-001',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $attemptId = DB::table('payment_attempts')->insertGetId([
            'tenant_id' => 101,
            'payment_id' => $paymentId,
            'merchant_reference' => 'BILL-SEC-001',
            'provider_transaction_id' => md5('khqr_sec_001'),
            'amount' => 50.00,
            'currency' => 'USD',
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Fake Bakong returning ONLY $1.00 paid for a $50.00 attempt -> must reject settlement
        Http::fake([
            '*' => Http::response([
                'responseCode' => 0,
                'responseMessage' => 'Success',
                'data' => [
                    'amount' => 1.00,
                    'currency' => 'USD',
                    'hash' => 'hash_tampered_1usd',
                ],
            ], 200),
        ]);

        $this->postJson("/api/v1/payments/{$attemptId}/confirm-hash", [
            'hash' => 'hash_tampered_1usd',
            'token' => 'fake_bakong_token',
            'amount' => 50.00,
        ])->assertStatus(422)
            ->assertJsonPath('paid', false);

        // Webhook with force_settle flag must not bypass verification
        $this->postJson('/api/v1/payments/khqr-webhook', [
            'merchant_reference' => 'BILL-SEC-001',
            'hash' => 'hash_tampered_1usd',
            'force_settle' => true,
            'status' => 'SUCCESS',
        ])->assertStatus(202)
            ->assertJsonPath('data.settled', false);

        $this->assertDatabaseHas('payment_attempts', [
            'id' => $attemptId,
            'status' => 'pending',
        ]);

        // Simulation endpoint blocked by default unless explicitly opted in
        Config::set('services.payments.allow_simulation', false);
        $this->postJson("/api/v1/payments/{$attemptId}/simulate-pay")->assertStatus(403);
    }

    public function test_online_order_recomputes_catalog_prices_and_rejects_cross_tenant_product_mixing(): void
    {
        $prodA = Product::create([
            'tenant_id' => '101',
            'name' => 'Iced Latte',
            'sku' => 'LATTE-A',
            'selling_price' => 4.50,
            'is_active' => true,
        ]);

        $prodB = Product::create([
            'tenant_id' => '202',
            'name' => 'Secret Matcha',
            'sku' => 'MATCHA-B',
            'selling_price' => 9.00,
            'is_active' => true,
        ]);

        // Attacker supplies forged $0.01 unit_price -> server must compute 2 * $4.50 = $9.00 + $0.90 tax = $9.90
        $res = $this->postJson('/api/v1/online-orders', [
            'customer_name' => 'Alice',
            'customer_phone' => '012345678',
            'delivery_type' => 'pickup',
            'items' => [
                [
                    'product_id' => $prodA->id,
                    'quantity' => 2,
                    'unit_price' => 0.01,
                ],
            ],
        ])->assertStatus(201);

        $this->assertEquals(9.00, (float) $res->json('data.subtotal'));
        $this->assertEquals(9.90, (float) $res->json('data.grand_total'));

        // Mixing products from tenant 101 and 202 must fail with 403
        $this->postJson('/api/v1/online-orders', [
            'customer_name' => 'Alice',
            'customer_phone' => '012345678',
            'delivery_type' => 'pickup',
            'items' => [
                ['product_id' => $prodA->id, 'quantity' => 1],
                ['product_id' => $prodB->id, 'quantity' => 1],
            ],
        ])->assertStatus(403);
    }

    public function test_checkout_rejects_cross_tenant_product_and_excessive_discount(): void
    {
        $prodB = Product::create([
            'tenant_id' => '202',
            'name' => 'Tenant B Item',
            'sku' => 'TB-001',
            'selling_price' => 10.00,
            'is_active' => true,
        ]);

        $prodA = Product::create([
            'tenant_id' => '101',
            'name' => 'Tenant A Item',
            'sku' => 'TA-001',
            'selling_price' => 10.00,
            'is_active' => true,
        ]);

        $userA = User::factory()->create(['role' => 'cashier']);
        $userA->tenant_id = '101';
        Sanctum::actingAs($userA, ['*']);

        // Cross-tenant product in checkout payload
        $this->postJson('/api/v1/sales', [
            'outlet_id' => 1,
            'register_id' => 1,
            'tender_type' => 'cash',
            'idempotency_key' => 'idem-cross-tenant-' . Str::random(8),
            'items' => [
                ['product_id' => $prodB->id, 'quantity' => 1, 'unit_price' => 10.00],
            ],
            'tenders' => [
                ['tender_type' => 'cash', 'amount' => 10.00],
            ],
        ])->assertStatus(403);

        // Discount exceeding subtotal rejected cleanly (422)
        $this->postJson('/api/v1/sales', [
            'outlet_id' => 1,
            'register_id' => 1,
            'tender_type' => 'cash',
            'discount_total' => 50.00,
            'idempotency_key' => 'idem-bad-discount-' . Str::random(8),
            'items' => [
                ['product_id' => $prodA->id, 'quantity' => 1, 'unit_price' => 10.00],
            ],
            'tenders' => [
                ['tender_type' => 'cash', 'amount' => 10.00],
            ],
        ])->assertStatus(422);
    }

    public function test_rbac_denies_customer_role_on_protected_finance_reports_and_shifts(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $customer->tenant_id = '101';
        Sanctum::actingAs($customer, ['*']);

        $this->getJson('/api/v1/expenses')->assertStatus(403);
        $this->getJson('/api/v1/reconciliation/exceptions')->assertStatus(403);
        $this->getJson('/api/v1/shifts/variance-alerts')->assertStatus(403);
        $this->getJson('/api/v1/admin/dashboard/summary')->assertStatus(403);
        $this->getJson('/api/v1/reports/sales')->assertStatus(403);
        $this->getJson('/api/v1/sales')->assertStatus(403);
    }

    public function test_report_export_idor_and_path_traversal_and_cross_sale_refund_are_blocked(): void
    {
        $adminA = User::factory()->create(['role' => 'admin']);
        $adminA->tenant_id = '101';
        Sanctum::actingAs($adminA, ['*']);

        // Path traversal export ID blocked by ProtectAttackMiddleware (403) and malformed IDs rejected by controller (404)
        $this->get('/api/v1/reports/export-download/..%2F..%2F.env')
            ->assertStatus(403)
            ->assertJsonPath('code', 'PATH_TRAVERSAL_DETECTED');
        $this->get('/api/v1/reports/export-download/invalid_id!')->assertStatus(404);
        $this->getJson('/api/v1/reports/export-status/invalid_id!')->assertStatus(404);

        // Export belonging to Tenant 202 denied to Tenant 101 (403)
        Cache::put('export_status_EXP-AABBCCDDEEFF', [
            'tenant_id' => '202',
            'status' => 'completed',
            'format' => 'csv',
        ], 300);

        $this->getJson('/api/v1/reports/export-status/EXP-AABBCCDDEEFF')->assertStatus(403);
        $this->get('/api/v1/reports/export-download/EXP-AABBCCDDEEFF')->assertStatus(403);

        // Refund referencing another sale's line ID (IDOR) must fail
        $saleAId = DB::table('sales')->insertGetId([
            'tenant_id' => 101,
            'outlet_id' => 1,
            'register_id' => 1,
            'user_id' => $adminA->id,
            'receipt_number' => 'REC-A-001',
            'idempotency_key' => 'idem-sale-a-' . Str::random(6),
            'subtotal' => 10.00,
            'tax_total' => 0,
            'discount_total' => 0,
            'grand_total' => 10.00,
            'status' => 'completed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $saleBId = DB::table('sales')->insertGetId([
            'tenant_id' => 202,
            'outlet_id' => 2,
            'register_id' => 2,
            'user_id' => $adminA->id,
            'receipt_number' => 'REC-B-001',
            'idempotency_key' => 'idem-sale-b-' . Str::random(6),
            'subtotal' => 20.00,
            'tax_total' => 0,
            'discount_total' => 0,
            'grand_total' => 20.00,
            'status' => 'completed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $lineBId = DB::table('sale_lines')->insertGetId([
            'tenant_id' => 202,
            'sale_id' => $saleBId,
            'product_id' => 1,
            'product_name' => 'Secret Item',
            'unit_price' => 20.00,
            'quantity' => 1,
            'discount_amount' => 0,
            'subtotal' => 20.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson("/api/v1/sales/{$saleAId}/refund", [
            'reason' => 'Damaged',
            'items' => [
                [
                    'sale_line_id' => $lineBId,
                    'quantity' => 1,
                    'restock_decision' => 'wastage',
                ],
            ],
        ])->assertStatus(422);
    }

    public function test_inventory_transfers_purchases_and_expired_enforce_tenant_isolation(): void
    {
        $outletBId = DB::table('outlets')->insertGetId([
            'tenant_id' => 202,
            'name' => 'Tenant B Outlet',
            'code' => 'OB-202',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $productBId = DB::table('products')->insertGetId([
            'tenant_id' => 202,
            'name' => 'Tenant B Expiring Milk',
            'sku' => 'MILK-B',
            'selling_price' => 3.00,
            'expiry_date' => now()->addDays(5)->toDateString(),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $poBId = DB::table('purchase_orders')->insertGetId([
            'tenant_id' => 202,
            'po_number' => 'PO-202-001',
            'supplier' => 'Tenant B Supplier',
            'items_count' => 1,
            'est_total' => 100.00,
            'status' => 'ordered',
            'order_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $transferBId = DB::table('stock_transfers')->insertGetId([
            'tenant_id' => 202,
            'transfer_number' => 'TRF-202-001',
            'from_outlet_id' => $outletBId,
            'to_outlet_id' => $outletBId + 1,
            'user_id' => 1,
            'status' => 'dispatched',
            'dispatched_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $adminA = User::factory()->create(['role' => 'admin']);
        $adminA->tenant_id = '101';
        Sanctum::actingAs($adminA, ['*']);

        // Cross-tenant PO update/receive blocked
        $this->patchJson("/api/v1/purchase-orders/{$poBId}/status", ['status' => 'cancelled'])->assertStatus(403);
        $this->postJson("/api/v1/purchase-orders/{$poBId}/receive")->assertStatus(403);

        // Cross-tenant Transfer show/receive/dispute/resolve blocked
        $this->getJson("/api/v1/inventory/transfers/{$transferBId}")->assertStatus(403);
        $this->postJson("/api/v1/inventory/transfers/{$transferBId}/receive")->assertStatus(403);
        $this->patchJson("/api/v1/inventory/transfers/{$transferBId}/dispute", [
            'dispute_notes' => 'Tampered',
            'items' => [['product_id' => $productBId, 'disputed_quantity' => 1, 'dispute_reason' => 'short_shipped']],
        ])->assertStatus(403);
        $this->patchJson("/api/v1/inventory/transfers/{$transferBId}/resolve-dispute", [
            'resolution_action' => 'accept_shortage',
            'resolution_notes' => 'Tampered',
        ])->assertStatus(403);

        // Expired endpoint excludes Tenant B products for Tenant A
        $expiredResp = $this->getJson('/api/v1/inventory/expired')->assertStatus(200);
        $this->assertCount(0, $expiredResp->json('data'));

        // Cross-outlet-stock returns 404 when querying Tenant B product as Tenant A
        $this->getJson("/api/v1/inventory/cross-outlet-stock?product_id={$productBId}")->assertStatus(404);
    }
}
