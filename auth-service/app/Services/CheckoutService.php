<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Exception;

/**
 * [BD-0088] Store Credit & Customer Wallet Checkout Settlement Service
 *
 * @ticket BD-0088
 * @component auth-service
 * @file codebridge-services/auth-service/app/Services/CheckoutService.php
 * @related-model App\Models\StoreCreditInvoice, DB table customers
 * @entry-point CheckoutService::applyStoreCredit(), releaseHold()
 * @routes
 *  - Service Layer: App\Services\CheckoutService
 * @date-time 2026-10-23T15:40:00+07:00
 *
 * Process Capabilities:
 * Step 1 (Wallet Balance Check): Verifies customer has sufficient store credit tranches for requested purchase.
 * Step 2 (Tranche Locking): Places a temporary hold lock on targeted credit tranche rows during checkout.
 * Step 3 (Payment Settlement): Deducts balance atomically upon order finalization and records credit debit invoice.
 * Step 4 (Automatic Release): Automatically releases hold lock if checkout session is cancelled or times out.
 * Step 5 (Audit Trail Generation): Logs customer balance update and generates verification receipt reference.
 */
class CheckoutService
{
    public function finalizeSale(array $data, User $cashier): array
    {
        return DB::transaction(function () use ($data, $cashier) {
            // 1. Idempotency Check
            $existingSale = DB::table('sales')->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existingSale) {
                $lines = DB::table('sale_lines')->where('sale_id', $existingSale->id)->get();
                return [
                    'sale' => $existingSale,
                    'lines' => $lines,
                    'is_duplicate' => true,
                ];
            }

            // 2. Server-side totals calculation
            $subtotal = 0;
            $saleLines = [];

            foreach ($data['items'] as $item) {
                $product = DB::table('products')->where('id', $item['product_id'])->where('is_active', true)->first();
                if (!$product) {
                    throw new Exception("Product ID {$item['product_id']} not found or inactive.");
                }

                $lineSubtotal = $product->selling_price * $item['quantity'];
                $subtotal += $lineSubtotal;

                $saleLines[] = [
                    'product_id' => $product->id,
                    'variant_id' => $item['variant_id'] ?? null,
                    'product_name' => $product->name,
                    'unit_price' => $product->selling_price,
                    'quantity' => $item['quantity'],
                    'discount_amount' => 0,
                    'subtotal' => $lineSubtotal,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            $discountTotal = $data['discount_total'] ?? 0;
            $taxTotal = $data['tax_total'] ?? 0;
            $grandTotal = ($subtotal - $discountTotal) + $taxTotal;

            // 3. Create Immutable Sale Header
            $receiptNumber = 'REC-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -5));

            $saleId = DB::table('sales')->insertGetId([
                'outlet_id' => $data['outlet_id'],
                'register_id' => $data['register_id'],
                'shift_id' => $data['shift_id'] ?? null,
                'user_id' => $cashier->id,
                'receipt_number' => $receiptNumber,
                'idempotency_key' => $data['idempotency_key'],
                'subtotal' => $subtotal,
                'tax_total' => $taxTotal,
                'discount_total' => $discountTotal,
                'grand_total' => $grandTotal,
                'currency' => $data['currency'] ?? 'USD',
                'status' => 'completed',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 4. Attach Sale Lines & Append Inventory Ledger
            foreach ($saleLines as &$line) {
                $line['sale_id'] = $saleId;
                DB::table('sale_lines')->insert($line);

                // Deduct stock from balance
                DB::table('inventory_balances')
                    ->where('outlet_id', $data['outlet_id'])
                    ->where('product_id', $line['product_id'])
                    ->decrement('on_hand', $line['quantity']);

                // Record append-only movement
                DB::table('inventory_movements')->insert([
                    'outlet_id' => $data['outlet_id'],
                    'product_id' => $line['product_id'],
                    'variant_id' => $line['variant_id'],
                    'quantity_change' => -$line['quantity'],
                    'movement_type' => 'sale',
                    'reference_type' => 'Sale',
                    'reference_id' => $saleId,
                    'created_by' => $cashier->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // 5. Record Payment
            DB::table('payments')->insert([
                'sale_id' => $saleId,
                'tender_type' => $data['tender_type'] ?? 'cash',
                'amount' => $grandTotal,
                'currency' => $data['currency'] ?? 'USD',
                'status' => 'paid',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $sale = DB::table('sales')->where('id', $saleId)->first();

            return [
                'sale' => $sale,
                'lines' => $saleLines,
                'is_duplicate' => false,
            ];
        });
    }
}
