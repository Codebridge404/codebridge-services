<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\OfflineSyncConflict;
use App\Services\TenantAuthorizationService;
use App\Support\TenantCatalogCache;
use App\Traits\ResolvesTenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
    use ResolvesTenantContext;

    private function assertOutletAndRegisterTenant(?int $outletId, ?int $registerId, ?string $tenantId): void
    {
        if ($tenantId === null) {
            return;
        }
        if ($outletId !== null) {
            $outlet = DB::table('outlets')->where('id', $outletId)->first();
            if ($outlet && $outlet->tenant_id !== null && (string) $outlet->tenant_id !== (string) $tenantId) {
                abort(403, 'Unauthorized: Outlet belongs to another organization.');
            }
        }
        if ($registerId !== null) {
            $register = DB::table('registers')->where('id', $registerId)->first();
            if ($register && $register->tenant_id !== null && (string) $register->tenant_id !== (string) $tenantId) {
                abort(403, 'Unauthorized: Register belongs to another organization.');
            }
        }
    }

    /**
     * Batch Sync Offline POS Transactions (POST /api/v1/offline-sync)
     * Handles stock collision detection & resolution between multiple offline registers.
     */
    public function sync(Request $request)
    {
        $resolvedTenantId = $this->getTenantId($request, true);
        $tenantId = $resolvedTenantId === 'all' ? null : $resolvedTenantId;

        $validated = $request->validate([
            'register_id' => 'nullable|integer',
            'outlet_id' => 'nullable|integer',
            'transactions' => 'required|array|min:1|max:500',
            'transactions.*.offline_id' => 'required|string|max:100',
            'transactions.*.receipt_number' => 'required|string|max:100',
            'transactions.*.register_id' => 'nullable|integer',
            'transactions.*.outlet_id' => 'nullable|integer',
            'transactions.*.created_at' => 'nullable|string',
            'transactions.*.items' => 'required|array|min:1',
            'transactions.*.items.*.id' => 'required|integer',
            'transactions.*.items.*.name' => 'required|string|max:255',
            'transactions.*.items.*.quantity' => 'required|numeric|min:0.0001',
            'transactions.*.items.*.price' => 'required|numeric|min:0',
            'transactions.*.items.*.variant_id' => 'nullable|integer',
            'transactions.*.tenders' => 'required|array|min:1',
            'transactions.*.tenders.*.tender_type' => 'nullable|string|max:50',
            'transactions.*.tenders.*.amount' => 'nullable|numeric|min:0',
        ]);

        $user = $request->user();
        $userId = $user?->id ?? 1;
        $defaultOutletId = $validated['outlet_id']
            ?? $user?->outlet_id
            ?? DB::table('outlets')->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))->value('id')
            ?? 1;
        $defaultRegisterId = $validated['register_id']
            ?? $user?->register_id
            ?? DB::table('registers')->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))->value('id')
            ?? 1;

        $this->assertOutletAndRegisterTenant((int) $defaultOutletId, (int) $defaultRegisterId, $tenantId);

        // Pre-validate all product IDs against tenant scope before syncing
        $productIds = [];
        foreach ($validated['transactions'] as $tx) {
            foreach ($tx['items'] as $item) {
                $productIds[] = (int) $item['id'];
            }
        }
        $productsById = DB::table('products')
            ->whereIn('id', array_values(array_unique($productIds)))
            ->get()
            ->keyBy('id');

        foreach ($productIds as $pid) {
            $product = $productsById->get($pid);
            if ($product && $tenantId !== null && $product->tenant_id !== null && (string) $product->tenant_id !== (string) $tenantId) {
                abort(403, "Unauthorized: Product #{$pid} belongs to another organization.");
            }
        }

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

            // Idempotency Check (with cross-tenant boundary check)
            $existing = DB::table('sales')->where('offline_id', $offlineId)->first();
            if ($existing) {
                if ($tenantId !== null && $existing->tenant_id !== null && (string) $existing->tenant_id !== (string) $tenantId) {
                    abort(403, 'Unauthorized: Offline transaction identifier belongs to another organization.');
                }

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

            $txOutletId = (int) ($tx['outlet_id'] ?? $defaultOutletId);
            $txRegisterId = (int) ($tx['register_id'] ?? $defaultRegisterId);
            $this->assertOutletAndRegisterTenant($txOutletId, $txRegisterId, $tenantId);

            // Process offline transaction inside a database transaction
            DB::transaction(function () use (
                $tx,
                $userId,
                $tenantId,
                $txOutletId,
                $txRegisterId,
                $offlineId,
                &$syncedCount,
                &$conflictCount,
                &$results
            ) {
                $outletId = $txOutletId;
                $registerId = $txRegisterId;
                $offlineCreatedAt = $tx['created_at'] ?? now();

                $subtotal = 0;
                foreach ($tx['items'] as $item) {
                    $qty = (float) $item['quantity'];
                    $unitPrice = (float) $item['price'];
                    $subtotal += ($qty * $unitPrice);
                }

                $taxAmount = round($subtotal * 0.10, 2);
                $grandTotal = $subtotal + $taxAmount;

                // Insert Sale Record (always tenant-scoped)
                $saleId = DB::table('sales')->insertGetId([
                    'tenant_id' => $tenantId,
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
                ]);

                $txConflicts = [];

                foreach ($tx['items'] as $item) {
                    $productId = $item['id'];
                    $productName = $item['name'];
                    $qty = (float) $item['quantity'];
                    $unitPrice = (float) $item['price'];
                    $lineSubtotal = $qty * $unitPrice;
                    $variantId = $item['variant_id'] ?? null;

                    // Insert Sale Line (tenant-scoped)
                    DB::table('sale_lines')->insert([
                        'tenant_id' => $tenantId,
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

                    // Check Inventory Balance & Detect Stock Collisions (tenant-scoped)
                    $balance = DB::table('inventory_balances')
                        ->where('outlet_id', $outletId)
                        ->where('product_id', $productId)
                        ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
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

                // Insert Payment Tenders (tenant-scoped)
                foreach ($tx['tenders'] as $t) {
                    DB::table('payments')->insert([
                        'tenant_id' => $tenantId,
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

        if ($syncedCount > 0) {
            TenantCatalogCache::invalidate($tenantId !== null ? (string) $tenantId : null, 'products');
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
        $tenantId = $this->getTenantId($request, true);
        $isSuperAdminAll = ($tenantId === 'all' && TenantAuthorizationService::hasPlatformSuperAdminRole($request->user()));

        $query = OfflineSyncConflict::query()->with(['product', 'sale']);
        if (!$isSuperAdminAll) {
            $query->where('tenant_id', $tenantId);
        }

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

        $perPage = min(max(1, (int) ($request->input('per_page', 20))), 100);
        $conflicts = $query->orderByDesc('id')->paginate($perPage);

        $groupedCounts = DB::table('offline_sync_conflicts')
            ->when(!$isSuperAdminAll, fn ($q) => $q->where('tenant_id', $tenantId))
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $counts = [
            'pending' => (int) ($groupedCounts['pending'] ?? 0),
            'resolved' => (int) ($groupedCounts['resolved'] ?? 0),
            'dismissed' => (int) ($groupedCounts['dismissed'] ?? 0),
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
    public function showConflict(Request $request, $id)
    {
        $this->getTenantId($request, true);
        $conflict = OfflineSyncConflict::with(['product', 'sale', 'resolver'])->findOrFail($id);
        $this->authorizeTenantResource($conflict->tenant_id, $request, 'Offline sync conflict');

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
        $this->getTenantId($request, true);

        $validated = $request->validate([
            'resolution_action' => 'required|in:inventory_adjustment,write_off,dismiss',
            'notes' => 'nullable|string|max:1000',
            'adjust_quantity' => 'nullable|numeric',
        ]);

        $conflict = OfflineSyncConflict::findOrFail($id);
        $this->authorizeTenantResource($conflict->tenant_id, $request, 'Offline sync conflict');

        $user = $request->user();
        $userId = $user?->id ?? 1;
        $stockAdjusted = false;

        DB::transaction(function () use ($conflict, $validated, $userId, &$stockAdjusted) {
            $action = $validated['resolution_action'];
            $notes = $validated['notes'] ?? null;
            $adjustQty = isset($validated['adjust_quantity']) ? (float) $validated['adjust_quantity'] : null;

            // If an inventory restock/recount adjustment was requested
            if ($action === 'inventory_adjustment' && $adjustQty !== null && $adjustQty != 0) {
                $stockAdjusted = true;
                // Adjust balance within conflict's tenant scope
                $balance = DB::table('inventory_balances')
                    ->where('outlet_id', $conflict->outlet_id)
                    ->where('product_id', $conflict->product_id)
                    ->when($conflict->tenant_id !== null, fn ($q) => $q->where('tenant_id', $conflict->tenant_id))
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

        if ($stockAdjusted) {
            TenantCatalogCache::invalidate($conflict->tenant_id !== null ? (string) $conflict->tenant_id : null, 'products');
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Conflict resolved successfully.',
            'data' => $conflict->fresh(['product', 'sale', 'resolver']),
        ]);
    }
}
