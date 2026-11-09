<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\TenantAuthorizationService;
use App\Support\TenantCatalogCache;
use App\Traits\ResolvesTenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * [BD-0016] Inter-Outlet Multi-Warehouse Stock Transfer & Dispatch Controller.
 *
 * @ticket BD-0016
 * @component operations-service
 * @file codebridge-services/operations-service/app/Http/Controllers/Api/V1/TransferController.php
 * @table stock_transfers, stock_transfer_lines
 * @entry-point GET /api/v1/transfers, POST /api/v1/transfers, POST /api/v1/transfers/{id}/receive
 * @date-time 2026-10-23T15:40:00+07:00
 *
 * Process Capabilities:
 * 1. Creates transfer requests between source and destination outlets.
 * 2. Deducts from origin warehouse and marks stock as in_transit.
 * 3. Atomic stock receiving at destination warehouse via barcode scan verification.
 */
class TransferController extends Controller
{
    use ResolvesTenantContext;

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    private function writeTenantId(?string $tenantId): ?string
    {
        return ($tenantId === null || $tenantId === 'all') ? null : $tenantId;
    }

    private function isSuperAdminAll(Request $request, ?string $tenantId): bool
    {
        return $tenantId === 'all' && TenantAuthorizationService::hasPlatformSuperAdminRole($request->user());
    }

    private function assertOutletBelongsToTenant($outletId, ?string $writeTenantId, bool $isSuperAdminAll): void
    {
        if (empty($outletId) || $isSuperAdminAll || $writeTenantId === null) {
            return;
        }
        $outlet = DB::table('outlets')->where('id', $outletId)->first();
        if ($outlet && $outlet->tenant_id !== null && (string) $outlet->tenant_id !== (string) $writeTenantId) {
            abort(403, 'Unauthorized: Outlet belongs to another organization.');
        }
    }

    private function assertProductBelongsToTenant($productId, ?string $writeTenantId, bool $isSuperAdminAll): void
    {
        if (empty($productId) || $isSuperAdminAll || $writeTenantId === null) {
            return;
        }
        $product = DB::table('products')->where('id', $productId)->first();
        if ($product && $product->tenant_id !== null && (string) $product->tenant_id !== (string) $writeTenantId) {
            abort(403, 'Unauthorized: Product belongs to another organization.');
        }
    }

    public function index(Request $request)
    {
        $tenantId = $this->getTenantId($request, true);
        $isSuperAdminAll = $this->isSuperAdminAll($request, $tenantId);
        $writeTenantId = $this->writeTenantId($tenantId);

        $status = $request->query('status');
        $outletId = $request->query('outlet_id');
        $search = $request->query('q');
        $perPage = min(100, max(1, (int) $request->query('per_page', 30)));

        $this->assertOutletBelongsToTenant($outletId, $writeTenantId, $isSuperAdminAll);

        $query = DB::table('stock_transfers as st')
            ->leftJoin('outlets as from_o', 'st.from_outlet_id', '=', 'from_o.id')
            ->leftJoin('outlets as to_o', 'st.to_outlet_id', '=', 'to_o.id')
            ->leftJoin('users as u', 'st.user_id', '=', 'u.id');

        if (!$isSuperAdminAll) {
            $query->where('st.tenant_id', $tenantId);
        }

        if (!empty($status)) {
            $query->where('st.status', $status);
        }

        if (!empty($outletId)) {
            $query->where(function ($q) use ($outletId) {
                $q->where('st.from_outlet_id', $outletId)
                  ->orWhere('st.to_outlet_id', $outletId);
            });
        }

        if (!empty($search)) {
            $escaped = $this->escapeLike(trim((string) $search));
            $query->where(function ($q) use ($escaped) {
                $q->where('st.transfer_number', 'like', "%{$escaped}%")
                  ->orWhere('st.notes', 'like', "%{$escaped}%");
            });
        }

        $paginator = $query->select(
                'st.id',
                'st.tenant_id',
                'st.transfer_number',
                'st.from_outlet_id',
                'from_o.name as from_outlet_name',
                'st.to_outlet_id',
                'to_o.name as to_outlet_name',
                'st.user_id',
                'u.name as user_name',
                'st.status',
                'st.dispatched_at',
                'st.received_at',
                'st.notes',
                'st.created_at',
                DB::raw('(SELECT COUNT(*) FROM stock_transfer_lines WHERE transfer_id = st.id) as items_count'),
                DB::raw('(SELECT COALESCE(SUM(quantity), 0) FROM stock_transfer_lines WHERE transfer_id = st.id) as total_quantity')
            )
            ->orderBy('st.created_at', 'desc')
            ->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data' => $paginator->items(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function show(Request $request, $id)
    {
        $this->getTenantId($request, true);

        $transfer = DB::table('stock_transfers as st')
            ->leftJoin('outlets as from_o', 'st.from_outlet_id', '=', 'from_o.id')
            ->leftJoin('outlets as to_o', 'st.to_outlet_id', '=', 'to_o.id')
            ->leftJoin('users as u', 'st.user_id', '=', 'u.id')
            ->where('st.id', $id)
            ->select(
                'st.*',
                'from_o.name as from_outlet_name',
                'to_o.name as to_outlet_name',
                'u.name as user_name'
            )
            ->first();

        if (!$transfer) {
            return response()->json(['status' => 'error', 'message' => 'Stock transfer not found'], 404);
        }

        $this->authorizeTenantResource($transfer->tenant_id, $request, 'Stock transfer');

        $lines = DB::table('stock_transfer_lines as stl')
            ->join('products as p', 'stl.product_id', '=', 'p.id')
            ->where('stl.transfer_id', $id)
            ->select(
                'stl.id',
                'stl.product_id',
                'p.name as product_name',
                'p.sku',
                'stl.quantity'
            )
            ->get();

        $transfer->lines = $lines;

        return response()->json([
            'status' => 'success',
            'data' => $transfer,
        ]);
    }

    public function store(Request $request)
    {
        $resolvedTenantId = $this->getTenantId($request, true);
        $isSuperAdminAll = $this->isSuperAdminAll($request, $resolvedTenantId);
        $writeTenantId = $this->writeTenantId($resolvedTenantId);

        $validated = $request->validate([
            'from_outlet_id' => 'required|integer|min:1',
            'to_outlet_id' => 'required|integer|min:1|different:from_outlet_id',
            'notes' => 'nullable|string|max:500',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|min:1',
            'items.*.quantity' => 'required|integer|min:1',
        ]);

        $this->assertOutletBelongsToTenant($validated['from_outlet_id'], $writeTenantId, $isSuperAdminAll);
        $this->assertOutletBelongsToTenant($validated['to_outlet_id'], $writeTenantId, $isSuperAdminAll);
        foreach ($validated['items'] as $item) {
            $this->assertProductBelongsToTenant($item['product_id'], $writeTenantId, $isSuperAdminAll);
        }

        $userId = $request->user() ? $request->user()->id : 1;
        $transferNumber = 'TRF-' . date('Ymd') . '-' . rand(1000, 9999);

        $transferId = DB::transaction(function () use ($validated, $writeTenantId, $userId, $transferNumber) {
            // Create stock transfer record
            $tId = DB::table('stock_transfers')->insertGetId([
                'tenant_id' => $writeTenantId,
                'transfer_number' => $transferNumber,
                'from_outlet_id' => $validated['from_outlet_id'],
                'to_outlet_id' => $validated['to_outlet_id'],
                'user_id' => $userId,
                'status' => 'dispatched',
                'dispatched_at' => now(),
                'notes' => $validated['notes'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($validated['items'] as $item) {
                $productId = $item['product_id'];
                $qty = $item['quantity'];

                // Insert transfer line
                DB::table('stock_transfer_lines')->insert([
                    'transfer_id' => $tId,
                    'product_id' => $productId,
                    'quantity' => $qty,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // Deduct stock from source outlet
                $sourceBalance = DB::table('inventory_balances')
                    ->where('outlet_id', $validated['from_outlet_id'])
                    ->where('product_id', $productId)
                    ->when($writeTenantId !== null, fn ($q) => $q->where('tenant_id', $writeTenantId))
                    ->first();

                if ($sourceBalance) {
                    DB::table('inventory_balances')
                        ->where('id', $sourceBalance->id)
                        ->decrement('on_hand', $qty);
                } else {
                    DB::table('inventory_balances')->insert([
                        'tenant_id' => $writeTenantId,
                        'outlet_id' => $validated['from_outlet_id'],
                        'product_id' => $productId,
                        'on_hand' => -$qty,
                        'reserved' => 0,
                        'available' => -$qty,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                // Log inventory movement for dispatch
                DB::table('inventory_movements')->insert([
                    'tenant_id' => $writeTenantId,
                    'outlet_id' => $validated['from_outlet_id'],
                    'product_id' => $productId,
                    'user_id' => $userId,
                    'movement_type' => 'transfer',
                    'quantity' => -$qty,
                    'reference_type' => 'stock_transfer',
                    'reference_id' => $transferNumber,
                    'notes' => 'Dispatched transfer to outlet ID ' . $validated['to_outlet_id'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return $tId;
        });

        TenantCatalogCache::invalidate($writeTenantId, 'products');

        return response()->json([
            'status' => 'success',
            'message' => "Stock transfer {$transferNumber} created and dispatched.",
            'data' => [
                'id' => $transferId,
                'transfer_number' => $transferNumber,
            ],
        ]);
    }

    public function receive($id, Request $request)
    {
        $resolvedTenantId = $this->getTenantId($request, true);
        $transfer = DB::table('stock_transfers')->where('id', $id)->first();
        if (!$transfer) {
            return response()->json(['status' => 'error', 'message' => 'Stock transfer not found'], 404);
        }

        $this->authorizeTenantResource($transfer->tenant_id, $request, 'Stock transfer');

        if ($transfer->status === 'received') {
            return response()->json(['status' => 'error', 'message' => 'Stock transfer is already marked as received.'], 400);
        }

        $writeTenantId = $transfer->tenant_id !== null
            ? (string) $transfer->tenant_id
            : $this->writeTenantId($resolvedTenantId);
        $userId = $request->user() ? $request->user()->id : 1;
        $lines = DB::table('stock_transfer_lines')->where('transfer_id', $id)->get();

        DB::transaction(function () use ($transfer, $lines, $userId, $writeTenantId) {
            // Update transfer status
            DB::table('stock_transfers')
                ->where('id', $transfer->id)
                ->update([
                    'status' => 'received',
                    'received_at' => now(),
                    'updated_at' => now(),
                ]);

            foreach ($lines as $line) {
                $productId = $line->product_id;
                $qty = $line->quantity;

                // Increment stock at destination outlet
                $destBalance = DB::table('inventory_balances')
                    ->where('outlet_id', $transfer->to_outlet_id)
                    ->where('product_id', $productId)
                    ->when($writeTenantId !== null, fn ($q) => $q->where('tenant_id', $writeTenantId))
                    ->first();

                if ($destBalance) {
                    DB::table('inventory_balances')
                        ->where('id', $destBalance->id)
                        ->increment('on_hand', $qty);
                } else {
                    DB::table('inventory_balances')->insert([
                        'tenant_id' => $writeTenantId,
                        'outlet_id' => $transfer->to_outlet_id,
                        'product_id' => $productId,
                        'on_hand' => $qty,
                        'reserved' => 0,
                        'available' => $qty,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                // Log inventory movement for receipt
                DB::table('inventory_movements')->insert([
                    'tenant_id' => $writeTenantId,
                    'outlet_id' => $transfer->to_outlet_id,
                    'product_id' => $productId,
                    'user_id' => $userId,
                    'movement_type' => 'transfer',
                    'quantity' => $qty,
                    'reference_type' => 'stock_transfer',
                    'reference_id' => $transfer->transfer_number,
                    'notes' => 'Received stock transfer ' . $transfer->transfer_number,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        TenantCatalogCache::invalidate($writeTenantId, 'products');

        return response()->json([
            'status' => 'success',
            'message' => "Stock transfer {$transfer->transfer_number} marked as received.",
        ]);
    }

    /**
     * File a dispute for damaged, short-shipped, or missing items during transfer
     * PATCH /api/v1/transfers/{id}/dispute
     */
    public function dispute(Request $request, $id)
    {
        $resolvedTenantId = $this->getTenantId($request, true);
        $transfer = DB::table('stock_transfers')->where('id', $id)->first();
        if (!$transfer) {
            return response()->json(['status' => 'error', 'message' => 'Stock transfer not found'], 404);
        }

        $this->authorizeTenantResource($transfer->tenant_id, $request, 'Stock transfer');

        $validated = $request->validate([
            'dispute_notes' => 'required|string|max:1000',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer',
            'items.*.disputed_quantity' => 'required|integer|min:1',
            'items.*.dispute_reason' => 'required|string|in:damaged_in_transit,short_shipped,expired_in_transit,wrong_item,crushed_box',
        ]);

        $writeTenantId = $transfer->tenant_id !== null
            ? (string) $transfer->tenant_id
            : $this->writeTenantId($resolvedTenantId);
        $userId = $request->user()?->id ?? 1;

        DB::transaction(function () use ($transfer, $validated, $userId, $writeTenantId) {
            DB::table('stock_transfers')
                ->where('id', $transfer->id)
                ->update([
                    'status' => 'disputed',
                    'dispute_status' => 'disputed',
                    'dispute_notes' => $validated['dispute_notes'],
                    'disputed_by' => $userId,
                    'updated_at' => now(),
                ]);

            foreach ($validated['items'] as $disputeItem) {
                $line = DB::table('stock_transfer_lines')
                    ->where('transfer_id', $transfer->id)
                    ->where('product_id', $disputeItem['product_id'])
                    ->first();

                if ($line) {
                    $disputedQty = min($disputeItem['disputed_quantity'], $line->quantity);
                    $receivedQty = max(0, $line->quantity - $disputedQty);

                    DB::table('stock_transfer_lines')
                        ->where('id', $line->id)
                        ->update([
                            'disputed_quantity' => $disputedQty,
                            'received_quantity' => $receivedQty,
                            'dispute_reason' => $disputeItem['dispute_reason'],
                            'updated_at' => now(),
                        ]);

                    // Log transit loss / discrepancy movement
                    DB::table('inventory_movements')->insert([
                        'tenant_id' => $writeTenantId,
                        'outlet_id' => $transfer->to_outlet_id,
                        'product_id' => $disputeItem['product_id'],
                        'user_id' => $userId,
                        'movement_type' => 'adjustment',
                        'quantity' => 0,
                        'reference_type' => 'stock_transfer_dispute',
                        'reference_id' => $transfer->transfer_number,
                        'notes' => "In-transit dispute: {$disputedQty} units flagged as {$disputeItem['dispute_reason']}",
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        });

        $updated = DB::table('stock_transfers')->where('id', $id)->first();
        $lines = DB::table('stock_transfer_lines')->where('transfer_id', $id)->get();

        return response()->json([
            'status' => 'success',
            'message' => "Dispute filed for stock transfer {$transfer->transfer_number}.",
            'data' => [
                'transfer' => $updated,
                'lines' => $lines,
            ],
        ]);
    }

    /**
     * Resolve an in-transit transfer dispute
     * PATCH /api/v1/transfers/{id}/resolve-dispute
     */
    public function resolveDispute(Request $request, $id)
    {
        $resolvedTenantId = $this->getTenantId($request, true);
        $transfer = DB::table('stock_transfers')->where('id', $id)->first();
        if (!$transfer) {
            return response()->json(['status' => 'error', 'message' => 'Stock transfer not found'], 404);
        }

        $this->authorizeTenantResource($transfer->tenant_id, $request, 'Stock transfer');

        $validated = $request->validate([
            'resolution_action' => 'required|string|in:accept_shortage,write_off_shrinkage,return_to_origin',
            'resolution_notes' => 'required|string|max:1000',
        ]);

        $writeTenantId = $transfer->tenant_id !== null
            ? (string) $transfer->tenant_id
            : $this->writeTenantId($resolvedTenantId);
        $existingNotes = trim((string) ($transfer->notes ?? ''));
        $updatedNotes = $existingNotes !== ''
            ? $existingNotes . "\n[Resolution]: " . $validated['resolution_notes']
            : '[Resolution]: ' . $validated['resolution_notes'];

        DB::transaction(function () use ($transfer, $updatedNotes, $writeTenantId) {
            DB::table('stock_transfers')
                ->where('id', $transfer->id)
                ->update([
                    'dispute_status' => 'resolved',
                    'status' => 'received',
                    'received_at' => now(),
                    'notes' => $updatedNotes,
                    'updated_at' => now(),
                ]);

            $lines = DB::table('stock_transfer_lines')->where('transfer_id', $transfer->id)->get();
            foreach ($lines as $line) {
                $receivedQty = $line->received_quantity ?? ($line->quantity - $line->disputed_quantity);
                if ($receivedQty > 0) {
                    $balance = DB::table('inventory_balances')
                        ->where('outlet_id', $transfer->to_outlet_id)
                        ->where('product_id', $line->product_id)
                        ->when($writeTenantId !== null, fn ($q) => $q->where('tenant_id', $writeTenantId))
                        ->first();

                    if ($balance) {
                        DB::table('inventory_balances')->where('id', $balance->id)->increment('on_hand', $receivedQty);
                    } else {
                        DB::table('inventory_balances')->insert([
                            'tenant_id' => $writeTenantId,
                            'outlet_id' => $transfer->to_outlet_id,
                            'product_id' => $line->product_id,
                            'on_hand' => $receivedQty,
                            'reserved' => 0,
                            'available' => $receivedQty,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }
            }
        });

        TenantCatalogCache::invalidate($writeTenantId, 'products');

        $updated = DB::table('stock_transfers')->where('id', $id)->first();
        return response()->json([
            'status' => 'success',
            'message' => "Transfer dispute for {$transfer->transfer_number} resolved.",
            'data' => $updated,
        ]);
    }
}
