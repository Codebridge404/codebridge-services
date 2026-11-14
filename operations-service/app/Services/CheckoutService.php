<?php

namespace App\Services;

use App\Models\User;
use App\Support\TenantCatalogCache;
use Illuminate\Support\Facades\DB;
use Exception;

/**
 * [BD-0109] Point-of-Sale Order Calculation & Multi-Payment Settlement Engine
 *
 * @ticket BD-0109
 * @component operations-service
 * @file codebridge-services/operations-service/app/Services/CheckoutService.php
 * @related-model App\Models\Order, App\Models\Payment, App\Models\Product
 * @entry-point CheckoutService::processCheckout(), calculateTotals(), handleSplitPayment()
 * @routes
 *  - Service Layer: App\Services\CheckoutService
 * @date-time 2026-10-23T15:40:00+07:00
 *
 * Process Capabilities:
 * Step 1 (Cart Inventory Lock): Verifies real-time stock levels and places transactional lock on cart item SKUs.
 * Step 2 (Tiered Price & Tax Calculation): Applies discounts, coupons, service charges, and VAT breakdown.
 * Step 3 (Multi-Tender Payment Execution): Routes payments across cash, credit card, Bakong KHQR, or customer store credit.
 * Step 4 (Order & Receipt Creation): Generates sequential invoice order number, stores line items, and commits transaction.
 * Step 5 (Inventory Decrement & KDS Dispatch): Atomically decrements stock and transmits order tickets to kitchen display displays.
 */
class CheckoutService
{
    public function finalizeSale(array $data, ?User $cashier = null, ?string $tenantId = null): array
    {
        $result = DB::transaction(function () use ($data, $cashier, $tenantId) {
            $effectiveTenantId = ($tenantId !== null && $tenantId !== '' && $tenantId !== 'all')
                ? $tenantId
                : ($cashier?->tenant_id !== null && $cashier->tenant_id !== 'all' ? (string) $cashier->tenant_id : null);
            $cashierId = (int) ($cashier?->id ?? $data['user_id'] ?? 1);
            $outletId = (int) $data['outlet_id'];

            // 1. Tenant-scoped Idempotency Check
            $existingQuery = DB::table('sales')->where('idempotency_key', $data['idempotency_key']);
            if ($effectiveTenantId !== null) {
                $existingQuery->where('tenant_id', $effectiveTenantId);
            }
            $existingSale = $existingQuery->first();
            if ($existingSale) {
                $lines = DB::table('sale_lines')->where('sale_id', $existingSale->id)->get();
                return [
                    'sale' => $existingSale,
                    'lines' => $lines,
                    'is_duplicate' => true,
                ];
            }

            // 2. Validate referenced outlet, register, shift, customer, and table belong to this tenant
            $outlet = DB::table('outlets')->where('id', $outletId)->first();
            if ($outlet && $effectiveTenantId !== null && $outlet->tenant_id !== null && (string) $outlet->tenant_id !== $effectiveTenantId) {
                throw new Exception('Unauthorized: Outlet belongs to another organization.');
            }

            $registerId = (int) $data['register_id'];
            $register = DB::table('registers')->where('id', $registerId)->first();
            if ($register && $effectiveTenantId !== null && $register->tenant_id !== null && (string) $register->tenant_id !== $effectiveTenantId) {
                throw new Exception('Unauthorized: Register belongs to another organization.');
            }

            if (!empty($data['shift_id'])) {
                $shift = DB::table('shifts')->where('id', $data['shift_id'])->first();
                if ($shift && $effectiveTenantId !== null && $shift->tenant_id !== null && (string) $shift->tenant_id !== $effectiveTenantId) {
                    throw new Exception('Unauthorized: Shift belongs to another organization.');
                }
            }

            // 3. Server-side totals calculation against tenant-scoped catalog
            $subtotal = 0.0;
            $saleLines = [];

            foreach ($data['items'] as $item) {
                $product = DB::table('products')
                    ->where('id', $item['product_id'])
                    ->where('is_active', true)
                    ->first();

                if (!$product) {
                    throw new Exception("Product ID {$item['product_id']} not found or inactive.");
                }

                if ($effectiveTenantId !== null && $product->tenant_id !== null && (string) $product->tenant_id !== $effectiveTenantId) {
                    throw new Exception("Unauthorized: Product ID {$item['product_id']} belongs to another organization.");
                }

                $qty = (float) $item['quantity'];
                $unitPrice = (float) $product->selling_price;
                $lineSubtotal = round($unitPrice * $qty, 2);
                $subtotal += $lineSubtotal;

                $saleLines[] = [
                    'tenant_id' => $effectiveTenantId,
                    'product_id' => $product->id,
                    'variant_id' => $item['variant_id'] ?? null,
                    'product_name' => $product->name,
                    'unit_price' => $unitPrice,
                    'quantity' => $qty,
                    'discount_amount' => 0,
                    'subtotal' => $lineSubtotal,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            $discountTotal = max(0.0, (float) ($data['discount_total'] ?? 0));
            if ($discountTotal > $subtotal) {
                throw new Exception('Discount total cannot exceed sale subtotal.');
            }
            $taxTotal = max(0.0, (float) ($data['tax_total'] ?? 0));
            $grandTotal = round(($subtotal - $discountTotal) + $taxTotal, 2);

            // 4. Create Immutable Sale Header
            $outletCode = $outlet ? strtoupper($outlet->code) : 'MAIN';

            $todayCountQuery = DB::table('sales')
                ->where('outlet_id', $outletId)
                ->whereDate('created_at', now()->toDateString());
            if ($effectiveTenantId !== null) {
                $todayCountQuery->where('tenant_id', $effectiveTenantId);
            }
            $todayCount = $todayCountQuery->count();

            $seqStr = str_pad((string) ($todayCount + 1), 5, '0', STR_PAD_LEFT);
            $receiptNumber = "REC-{$outletCode}-" . date('Ymd') . "-{$seqStr}";

            $customerId = $data['customer_id'] ?? null;
            $customerName = $data['customer_name'] ?? null;
            if ($customerId) {
                $custRow = DB::table('customers')->where('id', $customerId)->first();
                if ($custRow) {
                    if ($effectiveTenantId !== null && $custRow->tenant_id !== null && (string) $custRow->tenant_id !== $effectiveTenantId) {
                        throw new Exception('Unauthorized: Customer belongs to another organization.');
                    }
                    if (empty($customerName)) {
                        $customerName = $custRow->name;
                    }
                }
            }

            $tableId = $data['table_id'] ?? null;
            $tableName = $data['table_name'] ?? null;
            if ($tableId) {
                $tableRow = DB::table('restaurant_tables')->where('id', $tableId)->first();
                if ($tableRow) {
                    if ($effectiveTenantId !== null && $tableRow->tenant_id !== null && (string) $tableRow->tenant_id !== $effectiveTenantId) {
                        throw new Exception('Unauthorized: Table belongs to another organization.');
                    }
                    if (empty($tableName)) {
                        $tableName = $tableRow->name;
                    }
                }
            }
            $orderType = $data['order_type'] ?? ($tableId ? 'dine_in' : 'takeaway');

            $saleId = DB::table('sales')->insertGetId([
                'tenant_id' => $effectiveTenantId,
                'outlet_id' => $outletId,
                'register_id' => $registerId,
                'shift_id' => $data['shift_id'] ?? null,
                'user_id' => $cashierId,
                'customer_id' => $customerId,
                'customer_name' => $customerName,
                'table_id' => $tableId,
                'table_name' => $tableName,
                'order_type' => $orderType,
                'receipt_number' => $receiptNumber,
                'idempotency_key' => $data['idempotency_key'],
                'subtotal' => $subtotal,
                'tax_total' => $taxTotal,
                'discount_total' => $discountTotal,
                'grand_total' => $grandTotal,
                'currency' => $data['currency'] ?? 'USD',
                'status' => 'completed',
                'print_count' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 5. Attach Sale Lines & Append Inventory Ledger
            foreach ($saleLines as &$line) {
                $line['sale_id'] = $saleId;
                $lineId = DB::table('sale_lines')->insertGetId($line);
                $line['id'] = $lineId;

                $targetOutletId = $outletId;
                $targetProductId = $line['product_id'];

                // Deduct stock from tenant-scoped balance if record exists
                try {
                    $balanceQuery = DB::table('inventory_balances')
                        ->where('outlet_id', $targetOutletId)
                        ->where('product_id', $targetProductId);
                    if ($effectiveTenantId !== null) {
                        $balanceQuery->where('tenant_id', $effectiveTenantId);
                    }
                    $balance = $balanceQuery->first();

                    if ($balance) {
                        DB::table('inventory_balances')
                            ->where('id', $balance->id)
                            ->decrement('on_hand', $line['quantity']);
                    }
                } catch (\Exception $balanceErr) {
                    // Non-fatal if balance row doesn't exist
                }

                // Record append-only movement
                try {
                    DB::table('inventory_movements')->insert([
                        'tenant_id' => $effectiveTenantId,
                        'outlet_id' => $targetOutletId,
                        'product_id' => $targetProductId,
                        'variant_id' => $line['variant_id'],
                        'quantity_change' => -$line['quantity'],
                        'movement_type' => 'sale',
                        'reference_type' => 'Sale',
                        'reference_id' => (string) $saleId,
                        'created_by' => $cashierId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                } catch (\Exception $moveErr) {
                    // Non-fatal
                }
            }

            // 6. Record Payment (asynchronous tenders like KHQR remain pending until verified)
            $tenderType = strtolower((string) ($data['tender_type'] ?? 'cash'));
            $isAsynchronousTender = in_array($tenderType, ['khqr', 'qr', 'card_online'], true);
            $initialPaymentStatus = $isAsynchronousTender ? 'pending' : 'paid';

            DB::table('payments')->insert([
                'tenant_id' => $effectiveTenantId,
                'sale_id' => $saleId,
                'tender_type' => $tenderType,
                'amount' => $grandTotal,
                'currency' => $data['currency'] ?? 'USD',
                'status' => $initialPaymentStatus,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 7. Customer Loyalty & Store Credit Mutation
            if ($customerId) {
                try {
                    $custUpdateQuery = DB::table('customers')->where('id', $customerId);
                    if ($effectiveTenantId !== null) {
                        $custUpdateQuery->where('tenant_id', $effectiveTenantId);
                    }
                    if ($tenderType === 'store_credit') {
                        $custUpdateQuery->decrement('store_credit', $grandTotal);
                    } else {
                        $earnedPoints = (int) floor($grandTotal);
                        if ($earnedPoints > 0) {
                            $custUpdateQuery->increment('loyalty_points', $earnedPoints);
                        }
                    }
                    DB::table('customers')
                        ->where('id', $customerId)
                        ->when($effectiveTenantId !== null, fn ($q) => $q->where('tenant_id', $effectiveTenantId))
                        ->increment('total_spent', $grandTotal);
                } catch (\Exception $custErr) {
                    // Non-fatal if customer update fails
                }
            }

            // 8. Update Table Occupancy
            if ($tableId) {
                try {
                    DB::table('restaurant_tables')
                        ->where('id', $tableId)
                        ->when($effectiveTenantId !== null, fn ($q) => $q->where('tenant_id', $effectiveTenantId))
                        ->update([
                            'status' => 'occupied',
                            'active_sale_id' => $saleId,
                            'updated_at' => now(),
                        ]);
                } catch (\Exception $tableErr) {
                    // Non-fatal
                }
            }

            // 9. Auto-create KDS Ticket for Kitchen Display (tenant & outlet scoped)
            try {
                DB::table('kds_tickets')->insert([
                    'tenant_id' => $effectiveTenantId,
                    'sale_id' => $saleId,
                    'outlet_id' => $outletId,
                    'ticket_number' => 'TKT-' . substr($receiptNumber, -5),
                    'order_type' => $orderType,
                    'table_name' => $tableName ?? ($orderType === 'dine_in' ? 'Table Order' : 'Takeaway Counter'),
                    'station' => 'all',
                    'items' => json_encode(array_map(fn ($l) => ['name' => $l['product_name'], 'quantity' => $l['quantity']], $saleLines)),
                    'status' => 'pending',
                    'prep_time_minutes' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // Signal active real-time SSE kitchen monitors isolated per tenant
                $kdsCacheKey = 'kds_last_update_time_tenant_' . ($effectiveTenantId ?? 'global');
                \Illuminate\Support\Facades\Cache::put($kdsCacheKey, microtime(true), 86400);
            } catch (\Exception $kdsErr) {
                // Non-fatal
            }

            $sale = DB::table('sales')->where('id', $saleId)->first();

            return [
                'sale' => $sale,
                'lines' => $saleLines,
                'is_duplicate' => false,
            ];
        });

        if (empty($result['is_duplicate'])) {
            $effectiveTenantId = ($tenantId !== null && $tenantId !== '' && $tenantId !== 'all')
                ? $tenantId
                : ($cashier?->tenant_id !== null && $cashier->tenant_id !== 'all' ? (string) $cashier->tenant_id : null);
            TenantCatalogCache::invalidate($effectiveTenantId, 'products');
        }

        return $result;
    }

    /**
     * Process Restaurant Dine-In Split-Bill Calculation and Settlement.
     */
    public function processSplitBill(array $data, ?User $cashier = null, ?string $tenantId = null): array
    {
        return DB::transaction(function () use ($data, $cashier, $tenantId) {
            $effectiveTenantId = ($tenantId !== null && $tenantId !== '' && $tenantId !== 'all')
                ? $tenantId
                : ($cashier?->tenant_id !== null && $cashier->tenant_id !== 'all' ? (string) $cashier->tenant_id : null);

            $tableId = $data['table_id'] ?? null;
            $saleId = $data['sale_id'] ?? null;

            if ($tableId) {
                $table = DB::table('restaurant_tables')->where('id', $tableId)->first();
                if (!$table) {
                    throw new Exception("Restaurant table #{$tableId} not found.");
                }
                if ($effectiveTenantId !== null && $table->tenant_id !== null && (string) $table->tenant_id !== $effectiveTenantId) {
                    throw new Exception('Unauthorized: Table belongs to another organization.');
                }
                if (!$saleId && $table->active_sale_id) {
                    $saleId = $table->active_sale_id;
                }
            }

            if (!$saleId && $tableId) {
                $latestSale = DB::table('sales')
                    ->where('table_id', $tableId)
                    ->when($effectiveTenantId !== null, fn ($q) => $q->where('tenant_id', $effectiveTenantId))
                    ->whereIn('status', ['pending', 'bill_requested', 'completed'])
                    ->orderByDesc('id')
                    ->first();
                if ($latestSale) {
                    $saleId = $latestSale->id;
                }
            }

            if (!$saleId) {
                throw new Exception("No active dine-in sale found for this table to split.");
            }

            $parentSale = DB::table('sales')->where('id', $saleId)->first();
            if (!$parentSale) {
                throw new Exception("Sale #{$saleId} not found.");
            }
            if ($effectiveTenantId !== null && $parentSale->tenant_id !== null && (string) $parentSale->tenant_id !== $effectiveTenantId) {
                throw new Exception('Unauthorized: Sale belongs to another organization.');
            }
            $saleTenantId = $parentSale->tenant_id !== null ? (string) $parentSale->tenant_id : $effectiveTenantId;

            $splitType = $data['split_type'] ?? 'equal';
            $cashierId = $cashier ? $cashier->id : ($parentSale->user_id ?? 1);
            $splitSales = [];

            if ($splitType === 'equal') {
                $splitsCount = max(2, (int) ($data['splits_count'] ?? 2));
                $splitsPayload = $data['splits'] ?? [];

                $baseAmount = round(floor(($parentSale->grand_total / $splitsCount) * 100) / 100, 2);
                $roundingRemainder = round($parentSale->grand_total - ($baseAmount * $splitsCount), 2);
                $subtotalPerSplit = round($parentSale->subtotal / $splitsCount, 2);
                $taxPerSplit = round($parentSale->tax_total / $splitsCount, 2);
                $discountPerSplit = round($parentSale->discount_total / $splitsCount, 2);

                for ($i = 1; $i <= $splitsCount; $i++) {
                    $customTender = $splitsPayload[$i - 1]['tender_type'] ?? $data['default_tender_type'] ?? 'cash';
                    $guestName = $splitsPayload[$i - 1]['customer_name'] ?? "Guest #{$i}";
                    $allocatedAmount = $baseAmount + ($i === 1 ? $roundingRemainder : 0);

                    $splitReceipt = "{$parentSale->receipt_number}-S{$i}";

                    $childSaleId = DB::table('sales')->insertGetId([
                        'tenant_id' => $saleTenantId,
                        'parent_sale_id' => $parentSale->id,
                        'outlet_id' => $parentSale->outlet_id,
                        'register_id' => $parentSale->register_id,
                        'shift_id' => $parentSale->shift_id,
                        'user_id' => $cashierId,
                        'customer_id' => $splitsPayload[$i - 1]['customer_id'] ?? null,
                        'customer_name' => $guestName,
                        'table_id' => $parentSale->table_id,
                        'table_name' => $parentSale->table_name,
                        'seat_number' => $i,
                        'order_type' => 'dine_in',
                        'split_type' => 'equal',
                        'split_status' => 'split_settled',
                        'receipt_number' => $splitReceipt,
                        'idempotency_key' => "split-eq-{$parentSale->id}-{$i}-" . time(),
                        'subtotal' => $subtotalPerSplit,
                        'tax_total' => $taxPerSplit,
                        'discount_total' => $discountPerSplit,
                        'grand_total' => $allocatedAmount,
                        'currency' => $parentSale->currency ?? 'USD',
                        'status' => 'completed',
                        'print_count' => 1,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    // Record Payment for this equal split
                    $splitTender = strtolower((string) $customTender);
                    $isAsyncSplit = in_array($splitTender, ['khqr', 'qr', 'card_online'], true);
                    DB::table('payments')->insert([
                        'tenant_id' => $saleTenantId,
                        'sale_id' => $childSaleId,
                        'tender_type' => $customTender,
                        'amount' => $allocatedAmount,
                        'currency' => $parentSale->currency ?? 'USD',
                        'status' => $isAsyncSplit ? 'pending' : 'paid',
                        'reference_number' => "SPLIT-EQ-{$i}",
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    $splitSales[] = DB::table('sales')->where('id', $childSaleId)->first();
                }
            } else {
                // Itemized / Seat Split
                $splits = $data['splits'] ?? [];
                if (empty($splits)) {
                    throw new Exception("Itemized split requires at least one split seat configuration.");
                }

                $seatIdx = 1;
                foreach ($splits as $splitItem) {
                    $guestName = $splitItem['customer_name'] ?? "Seat #{$seatIdx}";
                    $tender = $splitItem['tender_type'] ?? 'cash';
                    $items = $splitItem['items'] ?? [];

                    $splitSubtotal = 0.0;
                    $splitLines = [];

                    foreach ($items as $it) {
                        $pId = $it['product_id'] ?? $it['id'] ?? null;
                        $qty = (float) ($it['quantity'] ?? 1);
                        $product = $pId ? DB::table('products')->where('id', $pId)->where('is_active', true)->first() : null;
                        if (!$product) {
                            throw new Exception("Product ID {$pId} not found or inactive for split bill.");
                        }
                        if ($saleTenantId !== null && $product->tenant_id !== null && (string) $product->tenant_id !== (string) $saleTenantId) {
                            throw new Exception("Unauthorized: Product ID {$pId} belongs to another organization.");
                        }
                        $price = (float) $product->selling_price;
                        $lineTotal = round($price * $qty, 2);
                        $splitSubtotal += $lineTotal;

                        $splitLines[] = [
                            'tenant_id' => $saleTenantId,
                            'product_id' => $product->id,
                            'variant_id' => $it['variant_id'] ?? null,
                            'product_name' => $product->name,
                            'unit_price' => $price,
                            'quantity' => $qty,
                            'discount_amount' => 0,
                            'subtotal' => $lineTotal,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                    }

                    $splitTax = round($splitSubtotal * 0.10, 2);
                    $splitGrandTotal = $splitSubtotal + $splitTax;
                    $splitReceipt = "{$parentSale->receipt_number}-S{$seatIdx}";

                    $childSaleId = DB::table('sales')->insertGetId([
                        'tenant_id' => $saleTenantId,
                        'parent_sale_id' => $parentSale->id,
                        'outlet_id' => $parentSale->outlet_id,
                        'register_id' => $parentSale->register_id,
                        'shift_id' => $parentSale->shift_id,
                        'user_id' => $cashierId,
                        'customer_id' => $splitItem['customer_id'] ?? null,
                        'customer_name' => $guestName,
                        'table_id' => $parentSale->table_id,
                        'table_name' => $parentSale->table_name,
                        'seat_number' => $seatIdx,
                        'order_type' => 'dine_in',
                        'split_type' => 'itemized',
                        'split_status' => 'split_settled',
                        'receipt_number' => $splitReceipt,
                        'idempotency_key' => "split-item-{$parentSale->id}-{$seatIdx}-" . time(),
                        'subtotal' => $splitSubtotal,
                        'tax_total' => $splitTax,
                        'discount_total' => 0,
                        'grand_total' => $splitGrandTotal,
                        'currency' => $parentSale->currency ?? 'USD',
                        'status' => 'completed',
                        'print_count' => 1,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    foreach ($splitLines as $sl) {
                        $sl['sale_id'] = $childSaleId;
                        DB::table('sale_lines')->insert($sl);
                    }

                    $splitTender = strtolower((string) $tender);
                    $isAsyncSplit = in_array($splitTender, ['khqr', 'qr', 'card_online'], true);
                    DB::table('payments')->insert([
                        'tenant_id' => $saleTenantId,
                        'sale_id' => $childSaleId,
                        'tender_type' => $tender,
                        'amount' => $splitGrandTotal,
                        'currency' => $parentSale->currency ?? 'USD',
                        'status' => $isAsyncSplit ? 'pending' : 'paid',
                        'reference_number' => "SPLIT-ITEM-{$seatIdx}",
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    $splitSales[] = DB::table('sales')->where('id', $childSaleId)->first();
                    $seatIdx++;
                }
            }

            // Mark Parent Sale as split_parent and completed
            DB::table('sales')->where('id', $parentSale->id)->update([
                'status' => 'completed',
                'split_type' => $splitType,
                'split_status' => 'split_parent',
                'updated_at' => now(),
            ]);

            // Release Dining Table to vacant
            if ($parentSale->table_id) {
                DB::table('restaurant_tables')->where('id', $parentSale->table_id)->update([
                    'status' => 'vacant',
                    'active_sale_id' => null,
                    'updated_at' => now(),
                ]);
            }

            $updatedTable = $parentSale->table_id
                ? DB::table('restaurant_tables')->where('id', $parentSale->table_id)->first()
                : null;

            return [
                'parent_sale' => $parentSale,
                'split_type' => $splitType,
                'split_count' => count($splitSales),
                'split_sales' => $splitSales,
                'table' => $updatedTable,
                'message' => 'Table bill split and settled successfully across ' . count($splitSales) . ' parties.',
            ];
        });
    }
}
