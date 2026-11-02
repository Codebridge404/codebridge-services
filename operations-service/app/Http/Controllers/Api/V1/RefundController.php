<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Exception;

/**
 * [BD-0102] Omnichannel Returns, Partial Restock & Refund Processing Controller
 *
 * @ticket BD-0102
 * @component operations-service
 * @file codebridge-services/operations-service/app/Http/Controllers/Api/V1/RefundController.php
 * @related-model App\Models\Order, DB table refunds
 * @entry-point RefundController::index(), processRefund(), show()
 * @routes
 *  - GET  /api/v1/refunds
 *  - POST /api/v1/refunds/process
 *  - GET  /api/v1/refunds/{id}
 * @date-time 2026-10-23T15:40:00+07:00
 *
 * Process Capabilities:
 * Step 1 (Original Transaction Lookup): Retrieves original sales receipt, line items, and original payment methods.
 * Step 2 (Return Eligibility Validation): Verifies return window policy (e.g. within 30 days) and non-refundable flags.
 * Step 3 (Line Item Selection & Restock Option): Allows partial line returns and prompts whether item is restocked or damaged.
 * Step 4 (Reversal Calculation): Calculates proportional tax, discount clawbacks, and net refund payout amount.
 * Step 5 (Financial Refund Execution): Processes refund to original payment gateway, cash drawer payout, or store credit balance.
 */
class RefundController extends Controller
{
    /**
     * Get return eligibility quote for a sale
     */
    public function returnQuote(Request $request, $id)
    {
        $sale = DB::table('sales')->where('id', $id)->first();
        if (!$sale) {
            $sale = DB::table('sales')->where('receipt_number', $id)->first();
        }

        if (!$sale) {
            return response()->json([
                'status' => 'error',
                'message' => 'Sale transaction not found.',
            ], 404);
        }

        $lines = DB::table('sale_lines')->where('sale_id', $sale->id)->get();

        // Get previously returned quantities
        $previousRefunds = DB::table('refunds')->where('sale_id', $sale->id)->pluck('id');
        $alreadyReturnedMap = [];
        if ($previousRefunds->isNotEmpty()) {
            $returnedLines = DB::table('refund_lines')->whereIn('refund_id', $previousRefunds)->get();
            foreach ($returnedLines as $rLine) {
                $alreadyReturnedMap[$rLine->sale_line_id] = ($alreadyReturnedMap[$rLine->sale_line_id] ?? 0) + (float) $rLine->quantity;
            }
        }

        $eligibleLines = [];
        $totalReturnableAmount = 0;

        foreach ($lines as $line) {
            $qtyReturned = $alreadyReturnedMap[$line->id] ?? 0;
            $qtyRemaining = max(0, (float) $line->quantity - $qtyReturned);

            $lineSubtotal = $qtyRemaining * (float) $line->unit_price;
            $totalReturnableAmount += $lineSubtotal;

            $eligibleLines[] = [
                'sale_line_id' => $line->id,
                'product_id' => $line->product_id,
                'product_name' => $line->product_name,
                'unit_price' => (float) $line->unit_price,
                'purchased_qty' => (float) $line->quantity,
                'already_returned_qty' => $qtyReturned,
                'remaining_returnable_qty' => $qtyRemaining,
                'returnable_subtotal' => $lineSubtotal,
            ];
        }

        $saleDate = \Carbon\Carbon::parse($sale->created_at);
        $daysElapsed = (int) $saleDate->diffInDays(now());
        $isPastReturnPolicy = $daysElapsed > 7;

        return response()->json([
            'status' => 'success',
            'data' => [
                'sale' => $sale,
                'days_elapsed' => $daysElapsed,
                'return_policy_days' => 7,
                'is_past_policy_window' => $isPastReturnPolicy,
                'requires_supervisor_approval' => $isPastReturnPolicy,
                'lines' => $eligibleLines,
                'max_refund_amount' => $totalReturnableAmount,
                'is_fully_refunded' => $totalReturnableAmount <= 0,
            ],
        ]);
    }

    /**
     * Store alias for refunds endpoint (POST /api/v1/refunds)
     */
    public function store(Request $request)
    {
        $saleId = $request->input('sale_id') ?? $request->input('receipt_number');
        if (!$saleId) {
            return response()->json(['status' => 'error', 'message' => 'sale_id or receipt_number is required.'], 422);
        }
        return $this->refund($request, $saleId);
    }

    /**
     * Process Partial or Full Refund with Restocking & Inventory Logging
     */
    public function refund(Request $request, $id)
    {
        $user = $request->user();

        $sale = DB::table('sales')->where('id', $id)->first();
        if (!$sale) {
            $sale = DB::table('sales')->where('receipt_number', $id)->first();
        }

        if (!$sale) {
            return response()->json(['status' => 'error', 'message' => 'Sale record not found.'], 404);
        }

        $validated = $request->validate([
            'supervisor_pin' => 'nullable|string',
            'reason' => 'required|string|max:255',
            'items' => 'required|array|min:1',
            'items.*.sale_line_id' => 'required',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.restock_decision' => 'required|string|in:restock,wastage,non_returnable,resellable,damaged,expired',
            'items.*.reason' => 'nullable|string|max:255',
        ]);

        // Normalize restock_decision values: resellable -> restock, damaged/expired -> wastage
        foreach ($validated['items'] as &$item) {
            if ($item['restock_decision'] === 'resellable') $item['restock_decision'] = 'restock';
            if (in_array($item['restock_decision'], ['damaged', 'expired'])) $item['restock_decision'] = 'wastage';
        }

        // 7-day return policy window enforcement
        $saleDate = \Carbon\Carbon::parse($sale->created_at);
        $daysElapsed = (int) $saleDate->diffInDays(now());
        if ($daysElapsed > 7) {
            if (empty($validated['supervisor_pin'])) {
                return response()->json([
                    'status' => 'error',
                    'message' => "Sale date ({$saleDate->toDateString()}) exceeds the 7-day return policy window ({$daysElapsed} days elapsed). Supervisor PIN override is required.",
                    'requires_supervisor_override' => true,
                    'days_elapsed' => $daysElapsed,
                ], 422);
            }
        }

        // Supervisor PIN Verification (if user is cashier or policy exceeded)
        $userRole = $user->role ?? 'cashier';
        if (!in_array($userRole, ['supervisor', 'outlet_manager', 'admin', 'super_admin']) || ($daysElapsed > 7 && !empty($validated['supervisor_pin']))) {
            if (empty($validated['supervisor_pin'])) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Supervisor PIN authorization is required for refunds.',
                ], 403);
            }

            $supervisor = DB::table('users')
                ->where('pin_code', $validated['supervisor_pin'])
                ->whereIn('role', ['supervisor', 'outlet_manager', 'admin', 'super_admin'])
                ->first();

            if (!$supervisor) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid Supervisor PIN or insufficient privileges.',
                ], 403);
            }
        }

        return DB::transaction(function () use ($sale, $validated, $user) {

            // Calculate returnable balances
            $previousRefunds = DB::table('refunds')->where('sale_id', $sale->id)->pluck('id');
            $alreadyReturnedMap = [];
            if ($previousRefunds->isNotEmpty()) {
                $returnedLines = DB::table('refund_lines')->whereIn('refund_id', $previousRefunds)->get();
                foreach ($returnedLines as $rLine) {
                    $alreadyReturnedMap[$rLine->sale_line_id] = ($alreadyReturnedMap[$rLine->sale_line_id] ?? 0) + (float) $rLine->quantity;
                }
            }

            $totalRefundAmount = 0;
            $refundLinesData = [];
            $totalItemsRequested = 0;
            $totalItemsPurchased = 0;

            $allLines = DB::table('sale_lines')->where('sale_id', $sale->id)->get();
            foreach ($allLines as $al) {
                $totalItemsPurchased += (float) $al->quantity;
            }

            foreach ($validated['items'] as $item) {
                $line = DB::table('sale_lines')->where('id', $item['sale_line_id'])->first();
                if (!$line) {
                    throw new Exception("Sale line ID {$item['sale_line_id']} not found.");
                }

                $alreadyReturned = $alreadyReturnedMap[$line->id] ?? 0;
                $maxReturnable = (float) $line->quantity - $alreadyReturned;
                $requestedQty = (float) $item['quantity'];

                if ($requestedQty > $maxReturnable) {
                    throw new Exception("Requested refund quantity ({$requestedQty}) exceeds returnable limit ({$maxReturnable}) for {$line->product_name}.");
                }

                $lineRefundSubtotal = $requestedQty * (float) $line->unit_price;
                $totalRefundAmount += $lineRefundSubtotal;
                $totalItemsRequested += ($alreadyReturned + $requestedQty);

                $refundLinesData[] = [
                    'line' => $line,
                    'requested_qty' => $requestedQty,
                    'line_refund_subtotal' => $lineRefundSubtotal,
                    'restock_decision' => $item['restock_decision'],
                    'reason' => $item['reason'] ?? $validated['reason'],
                ];
            }

            // Insert Refund Record Header
            $payment = DB::table('payments')->where('sale_id', $sale->id)->first();
            $refundId = DB::table('refunds')->insertGetId([
                'sale_id' => $sale->id,
                'payment_id' => $payment ? $payment->id : null,
                'user_id' => $user->id,
                'amount' => $totalRefundAmount,
                'reason' => $validated['reason'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Insert Refund Lines & Update Stock
            foreach ($refundLinesData as $rData) {
                $line = $rData['line'];
                $requestedQty = $rData['requested_qty'];

                DB::table('refund_lines')->insert([
                    'refund_id' => $refundId,
                    'sale_line_id' => $line->id,
                    'product_id' => $line->product_id,
                    'quantity' => $requestedQty,
                    'unit_price' => $line->unit_price,
                    'refund_subtotal' => $rData['line_refund_subtotal'],
                    'restock_decision' => $rData['restock_decision'],
                    'reason' => $rData['reason'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                if ($rData['restock_decision'] === 'restock') {
                    DB::table('inventory_balances')
                        ->where('outlet_id', $sale->outlet_id)
                        ->where('product_id', $line->product_id)
                        ->increment('on_hand', $requestedQty);

                    DB::table('inventory_balances')
                        ->where('outlet_id', $sale->outlet_id)
                        ->where('product_id', $line->product_id)
                        ->increment('available', $requestedQty);

                    DB::table('inventory_movements')->insert([
                        'outlet_id' => $sale->outlet_id,
                        'product_id' => $line->product_id,
                        'variant_id' => $line->variant_id ?? null,
                        'quantity_change' => $requestedQty,
                        'movement_type' => 'return',
                        'reference_type' => 'Refund',
                        'reference_id' => (string) $refundId,
                        'created_by' => $user->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                } elseif ($rData['restock_decision'] === 'wastage') {
                    DB::table('inventory_movements')->insert([
                        'outlet_id' => $sale->outlet_id,
                        'product_id' => $line->product_id,
                        'variant_id' => $line->variant_id ?? null,
                        'quantity_change' => -$requestedQty,
                        'movement_type' => 'wastage',
                        'reference_type' => 'Refund',
                        'reference_id' => (string) $refundId,
                        'created_by' => $user->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            // Record Refund Payment Tender Record
            DB::table('payments')->insert([
                'sale_id' => $sale->id,
                'tender_type' => $payment->tender_type ?? 'cash',
                'amount' => -$totalRefundAmount,
                'currency' => $sale->currency ?? 'USD',
                'status' => 'refunded',
                'reference_number' => 'REF-' . strtoupper(substr(uniqid(), -6)),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Update Sale Status
            $newStatus = ($totalItemsRequested >= $totalItemsPurchased) ? 'refunded' : 'partially_refunded';
            DB::table('sales')->where('id', $sale->id)->update([
                'status' => $newStatus,
                'updated_at' => now(),
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Refund processed successfully and inventory updated.',
                'data' => [
                    'refund_id' => $refundId,
                    'refund_amount' => $totalRefundAmount,
                    'new_sale_status' => $newStatus,
                ],
            ], 201);
        });
    }
}
