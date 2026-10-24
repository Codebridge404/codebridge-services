<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\OfflineSyncConflict;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Exception;

/**
 * [BD-0053] Offline POS Batch Synchronization & Conflict Resolution Engine.
 *
 * @ticket BD-0053
 * @component operations-service
 * @file codebridge-services/operations-service/app/Http/Controllers/Api/V1/OfflineSyncController.php
 * @related-model App\Models\OfflineSyncConflict, App\Models\Sale
 * @entry-point POST /api/v1/offline-sync, GET /api/v1/offline-sync/conflicts
 * @date-time 2026-10-23T15:35:00+07:00
 *
 * Capabilities:
 * - Idempotent replay of offline sales using offline_id constraint.
 * - Multi-register stock collision detection when on_hand inventory drops below zero.
 * - Managerial resolution workflows (force_fulfill, split_backorder, refund_customer).
 */
class OfflineSyncController extends Controller
{
    /**
     * Batch Sync Offline POS Transactions (POST /api/v1/offline-sync)
     * Handles stock collision detection & resolution between multiple offline registers.
     */
    public function sync(Request $request)
    {
        $validated = $request->validate([
            'register_id' => 'nullable|integer',
            'outlet_id' => 'nullable|integer',
            'transactions' => 'required|array|min:1',
            'transactions.*.offline_id' => 'required|string',
            'transactions.*.receipt_number' => 'required|string',
            'transactions.*.register_id' => 'nullable|integer',
            'transactions.*.outlet_id' => 'nullable|integer',
            'transactions.*.created_at' => 'nullable|string',
            'transactions.*.items' => 'required|array|min:1',
            'transactions.*.items.*.id' => 'required',
            'transactions.*.items.*.name' => 'required|string',
            'transactions.*.items.*.quantity' => 'required|numeric|min:0.0001',
            'transactions.*.items.*.price' => 'required|numeric|min:0',
            'transactions.*.items.*.variant_id' => 'nullable',
            'transactions.*.tenders' => 'required|array|min:1',
            'transactions.*.tenders.*.tender_type' => 'nullable|string',
            'transactions.*.tenders.*.amount' => 'nullable|numeric',
        ]);

        $user = $request->user();
        $userId = $user?->id ?? 1;
        $tenantId = $user?->tenant_id ?? 1;
        $defaultOutletId = $validated['outlet_id'] ?? $user?->outlet_id ?? 1;
        $defaultRegisterId = $validated['register_id'] ?? $user?->register_id ?? 1;

        $syncedCount = 0;
        $alreadySyncedCount = 0;
        $conflictCount = 0;
        $results = [];

        // Sort transactions chronologically so earlier offline sales claim stock first
        $transactions = $validated['transactions'];
        usort($transactions, function ($a, $b) {
            $timeA = isset($a['created_at']) ? strtotime($a['created_at']) : 0;
            $timeB = isset($b['created_at']) ? strtotime($b['created_at']) : 0;
            return $timeA <=> $timeB;
        });

        foreach ($transactions as $tx) {
            $offlineId = $tx['offline_id'];

            // Idempotency Check: if this offline transaction was already processed, return existing
            $existing = DB::table('sales')->where('offline_id', $offlineId)->first();
            if ($existing) {
                $alreadySyncedCount++;
                $results[] = [
                    'offline_id' => $offlineId,
                    'status' => 'already_synced',
                    'sale_id' => $existing->id,
                    'receipt_number' => $existing->receipt_number,
                    'conflicts' => [],
                ];
                continue;
            }

            // Process offline transaction inside a database transaction
            DB::transaction(function () use (
                $tx,
                $userId,
                $tenantId,
                $defaultOutletId,
                $defaultRegisterId,
                $offlineId,
                &$syncedCount,
                &$conflictCount,
                &$results
            ) {
                $outletId = $tx['outlet_id'] ?? $defaultOutletId;
                $registerId = $tx['register_id'] ?? $defaultRegisterId;
                $offlineCreatedAt = $tx['created_at'] ?? now();

                $subtotal = 0;
                foreach ($tx['items'] as $item) {
                    $qty = (float) $item['quantity'];
                    $unitPrice = (float) $item['price'];
                    $subtotal += ($qty * $unitPrice);
                }

                $taxAmount = round($subtotal * 0.10, 2);
                $grandTotal = $subtotal + $taxAmount;

                // Insert Sale Record
                $saleData = [
                    'offline_id' => $offlineId,
                    'receipt_number' => $tx['receipt_number'],
                    'idempotency_key' => $tx['idempotency_key'] ?? ('OFFLINE-' . $offlineId),
                    'outlet_id' => $outletId,
                    'register_id' => $registerId,
                    'user_id' => $userId,
                    'subtotal' => $subtotal,
                    'tax_total' => $taxAmount,
                    'grand_total' => $grandTotal,
                    'status' => 'completed',
                    'created_at' => $offlineCreatedAt,
                    'updated_at' => now(),
                ];

                if (Schema::hasColumn('sales', 'tenant_id')) {
                    $saleData['tenant_id'] = $tenantId;
                }

                $saleId = DB::table('sales')->insertGetId($saleData);

                $txConflicts = [];

                foreach ($tx['items'] as $item) {
                    $productId = $item['id'];
                    $productName = $item['name'];
                    $qty = (float) $item['quantity'];
                    $unitPrice = (float) $item['price'];
                    $lineSubtotal = $qty * $unitPrice;
                    $variantId = $item['variant_id'] ?? null;

                    // Insert Sale Line
                    DB::table('sale_lines')->insert([
                        'sale_id' => $saleId,
                        'product_id' => $productId,
                        'variant_id' => $variantId,
                        'product_name' => $productName,
                        'quantity' => $qty,
                        'unit_price' => $unitPrice,
                        'subtotal' => $lineSubtotal,
                        'created_at' => $offlineCreatedAt,
                        'updated_at' => now(),
                    ]);

                    // Check Inventory Balance & Detect Stock Collisions
                    $balance = DB::table('inventory_balances')
                        ->where('outlet_id', $outletId)
                        ->where('product_id', $productId)
                        ->lockForUpdate()
                        ->first();

                    $currentOnHand = $balance ? (float) $balance->on_hand : 0.0;

                    // Stock Collision Condition: Available stock is insufficient to satisfy offline sale
                    $isCollision = ($currentOnHand < $qty);

                    if ($isCollision) {
                        $shortage = $qty - max(0.0, $currentOnHand);
                        $conflictType = $currentOnHand <= 0.0 ? 'stock_depleted' : 'stock_collision';

                        // Create Conflict Record for audit & management review
                        $conflictId = DB::table('offline_sync_conflicts')->insertGetId([
                            'tenant_id' => $tenantId,
                            'outlet_id' => $outletId,
                            'register_id' => $registerId,
                            'sale_id' => $saleId,
                            'offline_id' => $offlineId,
                            'receipt_number' => $tx['receipt_number'],
                            'product_id' => $productId,
                            'product_name' => $productName,
                            'requested_quantity' => $qty,
                            'available_quantity' => $currentOnHand,
                            'shortage_quantity' => $shortage,
                            'conflict_type' => $conflictType,
                            'resolution_strategy' => 'allow_negative_stock',
                            'status' => 'pending',
                            'offline_created_at' => $offlineCreatedAt,
                            'metadata' => json_encode([
                                'register_id' => $registerId,
                                'on_hand_before_sync' => $currentOnHand,
                                'reason' => 'Concurrent sales on multiple offline registers caused stock deficit.',
                            ]),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);

                        $txConflicts[] = [
                            'conflict_id' => $conflictId,
                            'product_id' => $productId,
                            'product_name' => $productName,
                            'requested_quantity' => $qty,
                            'available_quantity' => $currentOnHand,
                            'shortage_quantity' => $shortage,
                            'conflict_type' => $conflictType,
                            'action_taken' => 'allowed_negative_stock',
                        ];
                    }

                    // Decrement inventory balance (negative stock allowed so on_hand matches physical reality)
                    if ($balance) {
                        DB::table('inventory_balances')
                            ->where('id', $balance->id)
                            ->decrement('on_hand', $qty);
                    } else {
                        DB::table('inventory_balances')->insert([
                            'tenant_id' => $tenantId,
                            'outlet_id' => $outletId,
                            'product_id' => $productId,
                            'variant_id' => $variantId,
                            'on_hand' => -$qty,
                            'reserved' => 0,
                            'available' => -$qty,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }

                    // Append to Immutable Inventory Movement Ledger
                    DB::table('inventory_movements')->insert([
                        'tenant_id' => $tenantId,
                        'outlet_id' => $outletId,
                        'product_id' => $productId,
                        'variant_id' => $variantId,
                        'quantity_change' => -$qty,
                        'movement_type' => $isCollision ? 'offline_sale_collision' : 'sale',
                        'reference_type' => 'Sale',
                        'reference_id' => (string) $saleId,
                        'created_by' => $userId,
                        'created_at' => $offlineCreatedAt,
                        'updated_at' => now(),
                    ]);
                }

                // Insert Payment Tenders
                foreach ($tx['tenders'] as $t) {
                    DB::table('payments')->insert([
                        'sale_id' => $saleId,
                        'tender_type' => $t['tender_type'] ?? 'cash',
                        'amount' => (float) ($t['amount'] ?? $grandTotal),
                        'currency' => 'USD',
                        'status' => 'paid',
                        'reference_number' => 'SYNC-' . strtoupper(substr(uniqid(), -6)),
                        'created_at' => $offlineCreatedAt,
                        'updated_at' => now(),
                    ]);
                }

                $hasConflicts = !empty($txConflicts);
                if ($hasConflicts) {
                    $conflictCount += count($txConflicts);
                }

                $syncedCount++;
                $results[] = [
                    'offline_id' => $offlineId,
                    'status' => $hasConflicts ? 'synced_with_conflict' : 'synced',
                    'sale_id' => $saleId,
                    'receipt_number' => $tx['receipt_number'],
                    'conflicts' => $txConflicts,
                ];
            });
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Batch offline transactions sync complete.',
            'summary' => [
                'total_received' => count($validated['transactions']),
                'synced_count' => $syncedCount,
                'already_synced_count' => $alreadySyncedCount,
                'conflict_count' => $conflictCount,
            ],
            'results' => $results,
        ]);
    }

    /**
     * List all offline sync stock collisions (GET /api/v1/offline-sync/conflicts)
     */
    public function conflicts(Request $request)
    {
        $query = OfflineSyncConflict::query()->with(['product', 'sale']);

        if ($request->filled('outlet_id')) {
            $query->where('outlet_id', $request->input('outlet_id'));
        }

        if ($request->filled('register_id')) {
            $query->where('register_id', $request->input('register_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('product_id')) {
            $query->where('product_id', $request->input('product_id'));
        }

        $perPage = min((int) ($request->input('per_page', 20)), 100);
        $conflicts = $query->orderByDesc('id')->paginate($perPage);

        $counts = [
            'pending' => OfflineSyncConflict::where('status', 'pending')->count(),
            'resolved' => OfflineSyncConflict::where('status', 'resolved')->count(),
            'dismissed' => OfflineSyncConflict::where('status', 'dismissed')->count(),
        ];

        return response()->json([
            'status' => 'success',
            'counts' => $counts,
            'data' => $conflicts->items(),
            'pagination' => [
                'current_page' => $conflicts->currentPage(),
                'per_page' => $conflicts->perPage(),
                'total' => $conflicts->total(),
                'last_page' => $conflicts->lastPage(),
            ],
        ]);
    }

    /**
     * Show single conflict details (GET /api/v1/offline-sync/conflicts/{id})
     */
    public function showConflict($id)
    {
        $conflict = OfflineSyncConflict::with(['product', 'sale', 'resolver'])->findOrFail($id);

        return response()->json([
            'status' => 'success',
            'data' => $conflict,
        ]);
    }

    /**
     * Resolve an offline sync stock collision (PATCH /api/v1/offline-sync/conflicts/{id}/resolve)
     */
    public function resolveConflict(Request $request, $id)
    {
        $validated = $request->validate([
            'resolution_action' => 'required|in:inventory_adjustment,write_off,dismiss',
            'notes' => 'nullable|string|max:1000',
            'adjust_quantity' => 'nullable|numeric',
        ]);

        $conflict = OfflineSyncConflict::findOrFail($id);
        $user = $request->user();
        $userId = $user?->id ?? 1;

        DB::transaction(function () use ($conflict, $validated, $userId) {
            $action = $validated['resolution_action'];
            $notes = $validated['notes'] ?? null;
            $adjustQty = isset($validated['adjust_quantity']) ? (float) $validated['adjust_quantity'] : null;

            // If an inventory restock/recount adjustment was requested
            if ($action === 'inventory_adjustment' && $adjustQty !== null && $adjustQty != 0) {
                // Adjust balance
                $balance = DB::table('inventory_balances')
                    ->where('outlet_id', $conflict->outlet_id)
                    ->where('product_id', $conflict->product_id)
                    ->lockForUpdate()
                    ->first();

                if ($balance) {
                    DB::table('inventory_balances')
                        ->where('id', $balance->id)
                        ->increment('on_hand', $adjustQty);
                } else {
                    DB::table('inventory_balances')->insert([
                        'tenant_id' => $conflict->tenant_id,
                        'outlet_id' => $conflict->outlet_id,
                        'product_id' => $conflict->product_id,
                        'on_hand' => $adjustQty,
                        'reserved' => 0,
                        'available' => $adjustQty,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                // Append adjustment to movement ledger
                DB::table('inventory_movements')->insert([
                    'tenant_id' => $conflict->tenant_id,
                    'outlet_id' => $conflict->outlet_id,
                    'product_id' => $conflict->product_id,
                    'quantity_change' => $adjustQty,
                    'movement_type' => 'reconciliation_adjustment',
                    'reference_type' => 'OfflineSyncConflict',
                    'reference_id' => (string) $conflict->id,
                    'created_by' => $userId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $conflict->update([
                'status' => $action === 'dismiss' ? 'dismissed' : 'resolved',
                'resolution_strategy' => $action,
                'resolution_notes' => $notes,
                'resolved_by' => $userId,
                'resolved_at' => now(),
            ]);
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Conflict resolved successfully.',
            'data' => $conflict->fresh(['product', 'sale', 'resolver']),
        ]);
    }
}
