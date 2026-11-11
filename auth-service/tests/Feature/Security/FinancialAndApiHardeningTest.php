<?php

namespace Tests\Feature\Security;

use App\Models\Customer;
use App\Models\Outlet;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\StoreCreditService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FinancialAndApiHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function createTenant(string $name): Tenant
    {
        return Tenant::create([
            'name' => $name,
            'slug' => Str::slug($name) . '-' . Str::lower(Str::random(5)),
            'status' => 'active',
            'client_tier' => 'enterprise_org',
            'max_users' => 100,
            'max_outlets' => 20,
            'max_registers' => 50,
        ]);
    }

    private function createWorkspaceUser(Tenant $tenant, string $role, ?Outlet $outlet = null): User
    {
        return User::factory()->inWorkspace()->create([
            'tenant_id' => $tenant->id,
            'outlet_uuid' => $outlet?->uuid,
            'role' => $role,
            'password' => Hash::make('Password123!'),
            'is_active' => true,
        ]);
    }

    public function test_role_show_enforces_tenant_isolation_and_system_role_visibility(): void
    {
        $tenantA = $this->createTenant('Role Scope Alpha');
        $tenantB = $this->createTenant('Role Scope Beta');
        $adminA = $this->createWorkspaceUser($tenantA, 'admin');

        $systemRole = Role::create([
            'name' => 'Cashier',
            'slug' => 'cashier',
            'is_system' => true,
            'tenant_id' => null,
            'company_id' => null,
        ]);

        $tenantARole = Role::create([
            'name' => 'Alpha Lead',
            'slug' => 'alpha_lead',
            'is_system' => false,
            'tenant_id' => $tenantA->id,
            'company_id' => $tenantA->id,
        ]);

        $tenantBRole = Role::create([
            'name' => 'Beta Secret Role',
            'slug' => 'beta_secret',
            'is_system' => false,
            'tenant_id' => $tenantB->id,
            'company_id' => $tenantB->id,
        ]);

        Sanctum::actingAs($adminA, ['*']);

        $this->getJson("/api/v1/roles/{$systemRole->id}")->assertStatus(200);
        $this->getJson("/api/v1/roles/{$tenantARole->id}")->assertStatus(200);
        $this->getJson("/api/v1/roles/{$tenantBRole->id}")->assertStatus(403);
    }

    public function test_allowed_and_denied_rbac_matrix_across_roles(): void
    {
        $tenant = $this->createTenant('Matrix Cafe');
        $outlet = Outlet::create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'name' => 'Matrix Main',
            'code' => 'MTX-01',
            'is_active' => true,
        ]);

        $admin = $this->createWorkspaceUser($tenant, 'admin', $outlet);
        $manager = $this->createWorkspaceUser($tenant, 'outlet_manager', $outlet);
        $cashier = $this->createWorkspaceUser($tenant, 'cashier', $outlet);
        $employee = $this->createWorkspaceUser($tenant, 'employee', $outlet);

        // Ensure roles exist in DB for store user checks
        foreach (['cashier', 'admin'] as $slug) {
            Role::firstOrCreate(
                ['slug' => $slug, 'tenant_id' => null],
                ['name' => ucfirst($slug), 'is_system' => true]
            );
        }

        // 1. Admin allowed actions
        Sanctum::actingAs($admin, ['*']);
        $this->postJson('/api/v1/roles', ['name' => 'Senior Barista'])->assertStatus(201);
        $this->postJson('/api/v1/outlets', [
            'name' => 'Matrix Branch 2',
            'code' => 'MTX-02',
            'business_type' => 'cafe_qsr',
        ])->assertStatus(201);

        // 2. Outlet Manager: cannot create custom roles or outlets or admin staff; can view users & create cashier in own outlet
        Sanctum::actingAs($manager, ['*']);
        $this->getJson('/api/v1/users')->assertStatus(200);
        $this->postJson('/api/v1/roles', ['name' => 'Mgr Escalation'])->assertStatus(403);
        $this->postJson('/api/v1/outlets', ['name' => 'Rogue Outlet', 'code' => 'RGE-01'])->assertStatus(403);
        $this->postJson('/api/v1/users', [
            'name' => 'Escalated Admin',
            'email' => 'rogue.admin@matrix.test',
            'password' => 'Password123!',
            'role' => 'admin',
            'outlet_id' => (string) $outlet->id,
        ])->assertStatus(403);
        $this->postJson('/api/v1/users', [
            'name' => 'Outlet Cashier',
            'email' => 'outlet.cashier@matrix.test',
            'password' => 'Password123!',
            'role' => 'cashier',
            'outlet_id' => (string) $outlet->id,
        ])->assertStatus(201);

        // 3. Cashier & Employee: allowed to list own tenant outlets, denied admin/user/role/security endpoints
        foreach ([$cashier, $employee] as $lowPrivUser) {
            Sanctum::actingAs($lowPrivUser, ['*']);
            $this->getJson('/api/v1/outlets')->assertStatus(200);
            $this->getJson('/api/v1/users')->assertStatus(403);
            $this->postJson('/api/v1/outlets', ['name' => 'Cashier Outlet', 'code' => 'CSH-01'])->assertStatus(403);
            $this->postJson('/api/v1/roles', ['name' => 'Cashier Role'])->assertStatus(403);
            $this->getJson('/api/v1/security-events')->assertStatus(403);
        }
    }

    public function test_financial_transaction_validation_overdraft_conversion_and_cross_tenant_rules(): void
    {
        $tenantA = $this->createTenant('Finance Alpha');
        $tenantB = $this->createTenant('Finance Beta');

        $adminA = $this->createWorkspaceUser($tenantA, 'admin');
        $customerA = Customer::create([
            'tenant_id' => $tenantA->id,
            'name' => 'Alice Customer',
            'store_credit' => 25.00,
            'store_credit_balance' => 25.00,
            'loyalty_points' => 50,
        ]);
        $customerB = Customer::create([
            'tenant_id' => $tenantB->id,
            'name' => 'Bob Customer',
            'store_credit' => 100.00,
            'store_credit_balance' => 100.00,
            'loyalty_points' => 500,
        ]);

        Sanctum::actingAs($adminA, ['*']);

        // 1. Negative / zero amounts rejected with 422 across all financial endpoints
        $this->postJson("/api/v1/customers/{$customerA->id}/credit/topup-invoice", ['amount_paid' => 0])->assertStatus(422);
        $this->postJson("/api/v1/customers/{$customerA->id}/credit/topup-invoice", ['amount_paid' => -15])->assertStatus(422);
        $this->postJson("/api/v1/customers/{$customerA->id}/credit/topup-invoice", ['amount_paid' => 10, 'bonus_credit' => -5])->assertStatus(422);
        $this->postJson("/api/v1/customers/{$customerA->id}/store-credit", ['amount_change' => 0, 'entry_type' => 'adjustment'])->assertStatus(422);
        $this->postJson('/api/v1/vouchers/issue', ['value' => 0])->assertStatus(422);
        $this->postJson('/api/v1/vouchers/issue', ['value' => -20])->assertStatus(422);
        $this->postJson('/api/v1/gift-cards', [])->assertStatus(422);
        $this->postJson('/api/v1/gift-cards', ['initial_value' => -10])->assertStatus(422);

        // 2. Service-level negative/zero guards (expectException terminates this method when thrown)
        $service = app(StoreCreditService::class);
        $this->expectException(\InvalidArgumentException::class);
        $service->createTopUpInvoice($customerA, -10.0);
    }

    public function test_financial_service_overdraft_and_cross_tenant_blocks_verify_cleanly(): void
    {
        $tenantA = $this->createTenant('Service Fin Alpha');
        $tenantB = $this->createTenant('Service Fin Beta');
        $adminA = $this->createWorkspaceUser($tenantA, 'admin');

        $customerA = Customer::create([
            'tenant_id' => $tenantA->id,
            'name' => 'Alice',
            'store_credit' => 25.00,
            'store_credit_balance' => 25.00,
            'loyalty_points' => 50,
        ]);
        $customerB = Customer::create([
            'tenant_id' => $tenantB->id,
            'name' => 'Bob',
            'store_credit' => 100.00,
            'store_credit_balance' => 100.00,
            'loyalty_points' => 500,
        ]);

        Sanctum::actingAs($adminA, ['*']);

        // Overdraft rejected
        $this->postJson("/api/v1/customers/{$customerA->id}/store-credit", [
            'amount_change' => -100,
            'entry_type' => 'checkout_use',
        ])->assertStatus(422);
        $this->assertEquals(25.0, (float) $customerA->fresh()->store_credit);

        // Loyalty <100 rejected, then 250 -> 200 redeemed for $2.00, remainder 50
        $this->postJson("/api/v1/customers/{$customerA->id}/convert-points")->assertStatus(422);
        $this->postJson("/api/v1/customers/{$customerA->id}/loyalty", ['points_change' => 200])->assertStatus(200);
        $convertRes = $this->postJson("/api/v1/customers/{$customerA->id}/convert-points")->assertStatus(200);
        $this->assertSame(50, $convertRes->json('data.new_points'));
        $this->assertEquals(27.0, $convertRes->json('data.new_credit'));

        // Gift card creation randomness & tenant scoping
        $gc1 = $this->postJson('/api/v1/gift-cards', ['initial_value' => 50])->assertStatus(201)->json('data.card_number');
        $gc2 = $this->postJson('/api/v1/gift-cards', ['initial_value' => 50])->assertStatus(201)->json('data.card_number');
        $this->assertMatchesRegularExpression('/^GC-[A-Z0-9]{4}-\d{4}$/', $gc1);
        $this->assertNotSame($gc1, $gc2);

        // Voucher assigned to Tenant B customer cannot be redeemed by Tenant A customer
        $voucherB = DB::table('digital_vouchers')->insertGetId([
            'tenant_id' => $tenantB->id,
            'voucher_code' => 'VCH-2026-BETA-9999',
            'title' => 'Beta Exclusive',
            'value' => 50,
            'currency' => 'USD',
            'status' => 'active',
            'expires_at' => now()->addDays(30),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertGreaterThan(0, $voucherB);

        $this->postJson('/api/v1/vouchers/redeem', [
            'voucher_code' => 'VCH-2026-BETA-9999',
            'customer_id' => $customerA->id,
        ])->assertStatus(422);

        // Cross-tenant customer financial operations blocked
        $this->postJson("/api/v1/customers/{$customerB->id}/store-credit", [
            'amount_change' => 50,
            'entry_type' => 'deposit',
        ])->assertStatus(404);
        $this->postJson("/api/v1/customers/{$customerB->id}/loyalty", ['points_change' => 100])->assertStatus(404);
        $this->postJson("/api/v1/customers/{$customerB->id}/convert-points")->assertStatus(404);
        $this->postJson("/api/v1/customers/{$customerB->id}/credit/topup-invoice", ['amount_paid' => 50])->assertStatus(404);
    }

    public function test_api_error_responses_redact_sql_and_stack_traces(): void
    {
        Route::get('/api/v1/_security-probe/query-exception', function () {
            throw new QueryException(
                'mysql',
                'SELECT password FROM users WHERE secret = ?',
                ['leak'],
                new \PDOException('SQLSTATE[42S02]: Base table or view not found: /var/www/html/secret.php')
            );
        });

        Route::get('/api/v1/_security-probe/runtime-exception', function () {
            throw new \RuntimeException('Sensitive internal path /var/www/html/app/Secret.php leaked');
        });

        // 1. Even with app.debug=true, raw SQL must NEVER be exposed in QueryException responses
        config(['app.debug' => true]);
        $dbRes = $this->getJson('/api/v1/_security-probe/query-exception');
        $dbRes->assertStatus(500)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('code', 'DATABASE_ERROR')
            ->assertJsonStructure(['status', 'code', 'message', 'request_id']);
        $this->assertStringNotContainsString('SQLSTATE', $dbRes->getContent());
        $this->assertStringNotContainsString('SELECT password FROM users', $dbRes->getContent());
        $this->assertStringNotContainsString('/var/www/html', $dbRes->getContent());

        // 2. When app.debug=false, arbitrary unhandled RuntimeException hides internal messages/paths/traces
        config(['app.debug' => false]);
        $errRes = $this->getJson('/api/v1/_security-probe/runtime-exception');
        $errRes->assertStatus(500)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('code', 'INTERNAL_SERVER_ERROR')
            ->assertJsonStructure(['status', 'code', 'message', 'request_id']);
        $this->assertStringNotContainsString('/var/www/html', $errRes->getContent());
        $this->assertStringNotContainsString('Secret.php', $errRes->getContent());
        $this->assertArrayNotHasKey('trace', $errRes->json());
    }
}
