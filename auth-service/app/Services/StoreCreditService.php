<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\DigitalVoucher;
use App\Models\StoreCreditInvoice;
use App\Models\User;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * [BD-0036] Enterprise Double-Entry Customer Store Credit & Digital Voucher Service.
 *
 * @ticket BD-0036
 * @component auth-service
 * @file codebridge-services/auth-service/app/Services/StoreCreditService.php
 * @related-controller App\Http\Controllers\Api\V1\StoreCreditInvoiceController
 * @related-command App\Console\Commands\ExpireStoreCreditCommand
 * @date-time 2026-10-23T15:35:00+07:00
 *
 * Core Capabilities:
 * 1. createTopUpInvoice() - Atomic top-up with bonus calculation and double-entry ledger.
 * 2. issueDigitalVoucher() - Secure random voucher code generation with validity windows.
 * 3. redeemDigitalVoucher() - Race-condition-free voucher redemption via lockForUpdate.
 * 4. processExpirations() - Daily automated reclaim of past-due credit tranches and vouchers.
 * 5. getCustomerStatement() - Running balance statements with upcoming 30-day expiration alerts.
 */
class StoreCreditService
{
    /**
     * Issue Top-Up Invoice and credit customer balance atomically.
     */
    public function createTopUpInvoice(
        Customer $customer,
        float $amountPaid,
        float $bonusCredit = 0.00,
        string $paymentMethod = 'cash',
        ?string $paymentReference = null,
        ?int $validityDays = null,
        ?string $notes = null,
        ?User $actor = null,
        ?int $outletId = null,
        ?string $tenantId = null
    ): array {
        return DB::transaction(function () use (
            $customer,
            $amountPaid,
            $bonusCredit,
            $paymentMethod,
            $paymentReference,
            $validityDays,
            $notes,
            $actor,
            $outletId,
            $tenantId
        ) {
            $customer = Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $totalCredit = $amountPaid + $bonusCredit;
            $expiresAt = $validityDays ? Carbon::now()->addDays($validityDays) : null;
            $invoiceNumber = 'INV-CR-' . date('Ymd') . '-' . strtoupper(Str::random(6));

            $invoice = StoreCreditInvoice::create([
                'tenant_id' => $tenantId ?? $customer->tenant_id,
                'invoice_number' => $invoiceNumber,
                'customer_id' => $customer->id,
                'amount_paid' => $amountPaid,
                'credit_amount' => $totalCredit,
                'bonus_credit' => $bonusCredit,
                'payment_method' => $paymentMethod,
                'payment_reference' => $paymentReference,
                'status' => 'paid',
                'cashier_id' => $actor?->id,
                'outlet_id' => $outletId,
                'expires_at' => $expiresAt,
                'notes' => $notes ?? 'Customer wallet store credit top-up',
            ]);

            $balanceBefore = (float) ($customer->store_credit ?? 0);
            $balanceAfter = $balanceBefore + $totalCredit;

            // Update customer balance
            $customer->update([
                'store_credit' => $balanceAfter,
                'store_credit_balance' => $balanceAfter,
            ]);

            // Append double-entry ledger record
            $ledgerId = DB::table('customer_credit_ledgers')->insertGetId([
                'tenant_id' => $tenantId ?? $customer->tenant_id,
                'customer_id' => $customer->id,
                'amount_change' => $totalCredit,
                'debit_amount' => 0.00,
                'credit_amount' => $totalCredit,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'entry_type' => 'topup_invoice',
                'reference_id' => $invoiceNumber,
                'invoice_id' => $invoice->id,
                'voucher_id' => null,
                'expires_at' => $expiresAt,
                'is_expired' => false,
                'notes' => $notes ?? "Top-Up invoice {$invoiceNumber} credited (\${$amountPaid} + \${$bonusCredit} bonus)",
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return [
                'invoice' => $invoice,
                'customer_id' => $customer->id,
                'previous_balance' => $balanceBefore,
                'credited_amount' => $totalCredit,
                'bonus_credit' => $bonusCredit,
                'new_balance' => $balanceAfter,
                'ledger_id' => $ledgerId,
                'expires_at' => $expiresAt?->toIso8601String(),
            ];
        });
    }

    /**
     * Issue a new digital gift voucher.
     */
    public function issueDigitalVoucher(
        float $value,
        string $title = 'Digital Voucher',
        ?int $validityDays = 90,
        ?int $customerId = null,
        ?User $actor = null,
        ?string $tenantId = null,
        ?string $notes = null
    ): DigitalVoucher {
        $voucherCode = 'VCH-' . date('Y') . '-' . strtoupper(Str::random(4)) . '-' . strtoupper(Str::random(4));
        $expiresAt = Carbon::now()->addDays($validityDays ?? 90);

        return DigitalVoucher::create([
            'tenant_id' => $tenantId,
            'voucher_code' => $voucherCode,
            'title' => $title,
            'value' => $value,
            'currency' => 'USD',
            'issued_to_customer_id' => $customerId,
            'redeemed_by_customer_id' => null,
            'redeemed_at' => null,
            'status' => 'active',
            'expires_at' => $expiresAt,
            'created_by' => $actor?->id,
            'notes' => $notes,
        ]);
    }

    /**
     * Redeem a digital voucher into customer store credit.
     */
    public function redeemDigitalVoucher(
        string $voucherCode,
        Customer $customer,
        ?User $actor = null
    ): array {
        return DB::transaction(function () use ($voucherCode, $customer, $actor) {
            $voucher = DigitalVoucher::where('voucher_code', trim($voucherCode))->where('tenant_id', $customer->tenant_id)->lockForUpdate()->first();

            if (!$voucher) {
                throw new Exception('Digital voucher not found or invalid code.');
            }

            if ($voucher->status === 'redeemed') {
                throw new Exception('This digital voucher has already been redeemed.');
            }

            if ($voucher->status === 'expired' || Carbon::parse($voucher->expires_at)->isPast()) {
                $voucher->update(['status' => 'expired']);
                throw new Exception('This digital voucher has expired and can no longer be redeemed.');
            }

            if ($voucher->status !== 'active') {
                throw new Exception("Digital voucher status is {$voucher->status} and cannot be redeemed.");
            }

            if ($voucher->issued_to_customer_id && $voucher->issued_to_customer_id != $customer->id) {
                throw new Exception('This digital voucher was issued to a different registered customer.');
            }

            $customer = Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $voucherValue = (float) $voucher->value;
            $balanceBefore = (float) ($customer->store_credit ?? 0);
            $balanceAfter = $balanceBefore + $voucherValue;

            // Mark voucher as redeemed
            $voucher->update([
                'status' => 'redeemed',
                'redeemed_by_customer_id' => $customer->id,
                'redeemed_at' => now(),
            ]);

            // Credit customer balance
            $customer->update([
                'store_credit' => $balanceAfter,
                'store_credit_balance' => $balanceAfter,
            ]);

            // Append double-entry ledger
            $ledgerId = DB::table('customer_credit_ledgers')->insertGetId([
                'tenant_id' => $customer->tenant_id,
                'customer_id' => $customer->id,
                'amount_change' => $voucherValue,
                'debit_amount' => 0.00,
                'credit_amount' => $voucherValue,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'entry_type' => 'voucher_redemption',
                'reference_id' => $voucher->voucher_code,
                'invoice_id' => null,
                'voucher_id' => $voucher->id,
                'expires_at' => null,
                'is_expired' => false,
                'notes' => "Redeemed digital voucher {$voucher->voucher_code} ({$voucher->title})",
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return [
                'voucher' => $voucher,
                'customer_id' => $customer->id,
                'voucher_value' => $voucherValue,
                'previous_balance' => $balanceBefore,
                'new_balance' => $balanceAfter,
                'ledger_id' => $ledgerId,
            ];
        });
    }

    /**
     * Process expirations across active vouchers and store credit tranches.
     */
    public function processExpirations(?string $tenantId = null): array
    {
        return DB::transaction(function () use ($tenantId) {
            $now = Carbon::now();

            // 1. Expire past-due digital vouchers
            $expiredVouchersCount = DigitalVoucher::where('status', 'active')
                ->where('expires_at', '<', $now)
                ->when($tenantId, fn($q) => $q->where('tenant_id', $tenantId))
                ->update(['status' => 'expired']);

            // 2. Expire past-due store credit ledger tranches
            $expiredLedgers = DB::table('customer_credit_ledgers')
                ->where('is_expired', false)
                ->where('credit_amount', '>', 0)
                ->whereNotNull('expires_at')
                ->where('expires_at', '<', $now)
                ->when($tenantId, fn($q) => $q->where('tenant_id', $tenantId))
                ->get();

            $totalExpiredCredit = 0.0;
            $affectedCustomers = 0;

            foreach ($expiredLedgers->sortBy('customer_id') as $ledger) {
                $customer = Customer::lockForUpdate()->find($ledger->customer_id);
                if (!$customer) {
                    continue;
                }

                // Recheck under the customer lock: another sweep may have handled this row.
                if (DB::table('customer_credit_ledgers')->where('id', $ledger->id)->lockForUpdate()->value('is_expired')) continue;
                $remaining = $this->remainingCredits($customer->id, true);
                $currentCredit = (float) $customer->store_credit;
                $creditToExpire = min($currentCredit, $remaining[$ledger->id] ?? 0);

                if ($creditToExpire > 0) {
                    $balanceAfter = max(0, $currentCredit - $creditToExpire);

                    $customer->update(['store_credit' => $balanceAfter, 'store_credit_balance' => $balanceAfter]);

                    DB::table('customer_credit_ledgers')->insert([
                        'tenant_id' => $customer->tenant_id,
                        'customer_id' => $customer->id,
                        'amount_change' => -$creditToExpire,
                        'debit_amount' => $creditToExpire,
                        'credit_amount' => 0.00,
                        'balance_before' => $currentCredit,
                        'balance_after' => $balanceAfter,
                        'entry_type' => 'expired_credit',
                        'reference_id' => $ledger->reference_id ?? 'EXP-' . $ledger->id,
                        'invoice_id' => $ledger->invoice_id,
                        'voucher_id' => $ledger->voucher_id,
                        'expires_at' => null,
                        'is_expired' => true,
                        'notes' => "Automated expiration of past-due credit tranche (Reference: {$ledger->reference_id})",
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    $totalExpiredCredit += $creditToExpire;
                    $affectedCustomers++;
                }

                DB::table('customer_credit_ledgers')->where('id', $ledger->id)->update([
                    'is_expired' => true,
                    'updated_at' => now(),
                ]);
            }

            return [
                'expired_vouchers' => $expiredVouchersCount,
                'expired_tranches' => count($expiredLedgers),
                'total_expired_credit' => $totalExpiredCredit,
                'affected_customers' => $affectedCustomers,
                'processed_at' => $now->toIso8601String(),
            ];
        });
    }

    /**
     * Get Customer Credit Balance Statement with running balances.
     */
    /**
     * Replay the ledger in posting order. Spending consumes the oldest credit first;
     * an expiration consumes only its referenced tranche, never a newer top-up.
     * Opening credit predating the ledger is a non-expiring first tranche.
     */
    public function remainingCredits(int $customerId, bool $lock = false): array
    {
        $query = DB::table('customer_credit_ledgers')->where('customer_id', $customerId)->orderBy('id');
        if ($lock) $query->lockForUpdate();
        $rows = $query->get();
        $remaining = [];
        $references = [];
        if ($rows->isNotEmpty() && (float) $rows->first()->balance_before > 0) {
            $remaining[0] = (float) $rows->first()->balance_before;
        }
        foreach ($rows as $row) {
            $change = (float) $row->amount_change;
            if ($change == 0) $change = (float) $row->credit_amount - (float) $row->debit_amount;
            if ($change > 0) {
                $remaining[$row->id] = $change;
                $references[$row->id] = $row->reference_id;
                continue;
            }
            $debit = -$change;
            if ($row->entry_type === 'expired_credit') {
                foreach ($references as $id => $reference) {
                    if ($reference !== null && $reference === $row->reference_id) {
                        $used = min($debit, $remaining[$id]);
                        $remaining[$id] -= $used;
                        $debit -= $used;
                    }
                }
                // Do not apply an old expiration to unrelated credits.
                continue;
            }
            foreach ($remaining as $id => $amount) {
                $used = min($debit, $amount);
                $remaining[$id] -= $used;
                $debit -= $used;
                if ($debit <= 0) break;
            }
        }
        return $remaining;
    }

    public function getCustomerStatement(int $customerId, ?string $startDate = null, ?string $endDate = null): ?array
    {
        $customer = Customer::find($customerId);
        if (!$customer) {
            return null;
        }

        $query = DB::table('customer_credit_ledgers')->where('customer_id', $customerId);

        if ($startDate) {
            $query->whereDate('created_at', '>=', $startDate);
        }
        if ($endDate) {
            $query->whereDate('created_at', '<=', $endDate);
        }

        $ledgers = $query->orderBy('id', 'desc')->get();

        $totalCredits = (float) DB::table('customer_credit_ledgers')
            ->where('customer_id', $customerId)
            ->sum('credit_amount');

        $totalDebits = (float) DB::table('customer_credit_ledgers')
            ->where('customer_id', $customerId)
            ->sum('debit_amount');

        // Upcoming expiring credit
        $remaining = $this->remainingCredits($customerId);
        $upcomingExpiring = DB::table('customer_credit_ledgers')
            ->where('customer_id', $customerId)
            ->where('is_expired', false)
            ->whereNotNull('expires_at')
            ->where('expires_at', '>', now())
            ->where('expires_at', '<=', now()->addDays(30))
            ->get()->sum(fn ($row) => $remaining[$row->id] ?? 0);

        return [
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'email' => $customer->email,
                'phone' => $customer->phone,
                'current_store_credit' => (float) $customer->store_credit,
                'loyalty_points' => (int) $customer->loyalty_points,
            ],
            'statement_summary' => [
                'total_lifetime_credit_loaded' => $totalCredits,
                'total_lifetime_credit_used' => $totalDebits,
                'expiring_in_next_30_days' => (float) $upcomingExpiring,
            ],
            'ledgers' => $ledgers,
        ];
    }
}
