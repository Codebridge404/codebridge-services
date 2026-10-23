<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\DigitalVoucher;
use App\Models\StoreCreditInvoice;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StoreCreditInvoicingTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_credit_invoicing_requires_authentication(): void
    {
        $this->postJson('/api/v1/customers/1/credit/topup-invoice', ['amount_paid' => 50])->assertStatus(401);
        $this->getJson('/api/v1/customers/1/credit/invoices')->assertStatus(401);
        $this->postJson('/api/v1/vouchers/issue', ['value' => 25])->assertStatus(401);
        $this->postJson('/api/v1/vouchers/redeem', ['voucher_code' => 'TEST', 'customer_id' => 1])->assertStatus(401);
        $this->getJson('/api/v1/customers/1/credit/statement')->assertStatus(401);
    }

    public function test_can_issue_store_credit_top_up_invoice(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($user, ['*']);

        $customer = Customer::create([
            'name' => 'Sophea Pich',
            'phone' => '+85512345678',
            'email' => 'sophea@example.com',
            'store_credit' => 10.00,
            'loyalty_points' => 50,
            'is_active' => true,
        ]);

        $response = $this->postJson("/api/v1/customers/{$customer->id}/credit/topup-invoice", [
            'amount_paid' => 100.00,
            'bonus_credit' => 10.00,
            'payment_method' => 'khqr',
            'payment_reference' => 'TXN-KHQR-9921',
            'validity_days' => 180,
            'notes' => 'Store VIP wallet top-up promotion',
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'customer_id' => $customer->id,
                    'previous_balance' => 10.00,
                    'credited_amount' => 110.00,
                    'bonus_credit' => 10.00,
                    'new_balance' => 120.00,
                ],
            ]);

        // Customer store credit balance updated
        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'store_credit' => 120.00,
        ]);

        // Invoice record created
        $this->assertDatabaseHas('store_credit_invoices', [
            'customer_id' => $customer->id,
            'amount_paid' => 100.00,
            'credit_amount' => 110.00,
            'payment_method' => 'khqr',
            'status' => 'paid',
        ]);

        // Ledger record created with running balances
        $this->assertDatabaseHas('customer_credit_ledgers', [
            'customer_id' => $customer->id,
            'entry_type' => 'topup_invoice',
            'credit_amount' => 110.00,
            'debit_amount' => 0.00,
            'balance_before' => 10.00,
            'balance_after' => 120.00,
        ]);
    }

    public function test_can_issue_and_redeem_digital_voucher(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($user, ['*']);

        $customer = Customer::create([
            'name' => 'Chanthy Kao',
            'phone' => '+85598765432',
            'store_credit' => 0.00,
            'is_active' => true,
        ]);

        // 1. Issue voucher
        $issueRes = $this->postJson('/api/v1/vouchers/issue', [
            'value' => 50.00,
            'title' => 'Water Festival Promo Voucher',
            'validity_days' => 60,
        ]);

        $issueRes->assertStatus(201)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'value' => 50.00,
                    'status' => 'active',
                ],
            ]);

        $voucherCode = $issueRes->json('data.voucher_code');
        $this->assertNotEmpty($voucherCode);

        // 2. Redeem voucher for customer
        $redeemRes = $this->postJson('/api/v1/vouchers/redeem', [
            'voucher_code' => $voucherCode,
            'customer_id' => $customer->id,
        ]);

        $redeemRes->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'customer_id' => $customer->id,
                    'voucher_value' => 50.00,
                    'previous_balance' => 0.00,
                    'new_balance' => 50.00,
                ],
            ]);

        // Customer wallet balance updated
        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'store_credit' => 50.00,
        ]);

        // Voucher marked redeemed
        $this->assertDatabaseHas('digital_vouchers', [
            'voucher_code' => $voucherCode,
            'status' => 'redeemed',
            'redeemed_by_customer_id' => $customer->id,
        ]);

        // Cannot redeem twice
        $doubleRedeemRes = $this->postJson('/api/v1/vouchers/redeem', [
            'voucher_code' => $voucherCode,
            'customer_id' => $customer->id,
        ]);
        $doubleRedeemRes->assertStatus(422);
    }

    public function test_expired_store_credit_and_vouchers_are_reclaimed(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($user, ['*']);

        $customer = Customer::create([
            'name' => 'Dara Meas',
            'store_credit' => 40.00,
            'is_active' => true,
        ]);

        // Insert expired voucher
        $expiredVoucher = DigitalVoucher::create([
            'voucher_code' => 'VCH-EXPIRED-TEST',
            'title' => 'Old Expired Voucher',
            'value' => 20.00,
            'status' => 'active',
            'expires_at' => Carbon::now()->subDays(5),
        ]);

        // Insert expired credit tranche in ledger
        DB::table('customer_credit_ledgers')->insert([
            'customer_id' => $customer->id,
            'amount_change' => 25.00,
            'debit_amount' => 0.00,
            'credit_amount' => 25.00,
            'balance_before' => 15.00,
            'balance_after' => 40.00,
            'entry_type' => 'topup_invoice',
            'reference_id' => 'INV-CR-OLD',
            'expires_at' => Carbon::now()->subDays(2),
            'is_expired' => false,
            'created_at' => Carbon::now()->subDays(30),
            'updated_at' => Carbon::now()->subDays(30),
        ]);

        // Run expiration command
        $exitCode = Artisan::call('store-credit:expire');
        $this->assertEquals(0, $exitCode);

        // Voucher status updated to expired
        $this->assertDatabaseHas('digital_vouchers', [
            'id' => $expiredVoucher->id,
            'status' => 'expired',
        ]);

        // Customer balance reduced by expired tranche (40.00 - 25.00 = 15.00)
        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'store_credit' => 15.00,
        ]);

        // Debit ledger entry logged
        $this->assertDatabaseHas('customer_credit_ledgers', [
            'customer_id' => $customer->id,
            'entry_type' => 'expired_credit',
            'debit_amount' => 25.00,
            'amount_change' => -25.00,
            'balance_after' => 15.00,
        ]);
    }

    public function test_customer_credit_statement_endpoint(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($user, ['*']);

        $customer = Customer::create([
            'name' => 'Borey Vong',
            'store_credit' => 150.00,
            'loyalty_points' => 120,
            'is_active' => true,
        ]);

        DB::table('customer_credit_ledgers')->insert([
            [
                'customer_id' => $customer->id,
                'amount_change' => 100.00,
                'debit_amount' => 0.00,
                'credit_amount' => 100.00,
                'balance_before' => 0.00,
                'balance_after' => 100.00,
                'entry_type' => 'topup_invoice',
                'reference_id' => 'INV-1',
                'created_at' => now()->subDays(10),
                'updated_at' => now()->subDays(10),
            ],
            [
                'customer_id' => $customer->id,
                'amount_change' => 50.00,
                'debit_amount' => 0.00,
                'credit_amount' => 50.00,
                'balance_before' => 100.00,
                'balance_after' => 150.00,
                'entry_type' => 'voucher_redemption',
                'reference_id' => 'VCH-1',
                'created_at' => now()->subDays(5),
                'updated_at' => now()->subDays(5),
            ],
        ]);

        $response = $this->getJson("/api/v1/customers/{$customer->id}/credit/statement");

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'customer' => [
                        'id' => $customer->id,
                        'name' => 'Borey Vong',
                        'current_store_credit' => 150.00,
                    ],
                    'statement_summary' => [
                        'total_lifetime_credit_loaded' => 150.00,
                    ],
                ],
            ]);

        $this->assertCount(2, $response->json('data.ledgers'));
    }
}
