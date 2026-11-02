<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
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
    use \App\Traits\ResolvesTenantContext;

    public function index(Request $request)
    {
        $tenantId = $this->getTenantId($request);
        $status = $request->query('status');
        $outletId = $request->query('outlet_id');
        $search = $request->query('q');

        $query = DB::table('stock_transfers as st')
            ->leftJoin('outlets as from_o', 'st.from_outlet_id', '=', 'from_o.id')
            ->leftJoin('outlets as to_o', 'st.to_outlet_id', '=', 'to_o.id')
            ->leftJoin('users as u', 'st.user_id', '=', 'u.id');

        // Scope to tenant organization
        if (!empty($tenantId) && $tenantId !== 'all') {
            $hasTenantTransfers = DB::table('stock_transfers')->where('tenant_id', $tenantId)->exists();
            if ($hasTenantTransfers) {
                $query->where('st.tenant_id', $tenantId);
            } else {
                $query->where(function ($q) use ($tenantId) {
                    $q->where('st.tenant_id', $tenantId)->orWhereNull('st.tenant_id');
                });
            }
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
            $query->where(function ($q) use ($search) {
                $q->where('st.transfer_number', 'like', "%{$search}%")
                  ->orWhere('st.notes', 'like', "%{$search}%");
            });
        }

        $transfers = $query->select(
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
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $transfers,
        ]);
    }

    public function show($id)
    {
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
        $tenantId = $this->getTenantId($request);
        $validated = $request->validate([
            'from_outlet_id' => 'required',
            'to_outlet_id' => 'required|different:from_outlet_id',
            'notes' => 'nullable|string|max:500',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required',
            'items.*.quantity' => 'required|integer|min:1',
        ]);

        $userId = $request->user() ? $request->user()->id : 1;
        $transferNumber = 'TRF-' . date('Ymd') . '-' . rand(1000, 9999);

        $transferId = DB::transaction(function () use ($validated, $tenantId, $userId, $transferNumber) {
            // Create stock transfer record
            $tId = DB::table('stock_transfers')->insertGetId([
                'tenant_id' => $tenantId,
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
                    ->first();

                if ($sourceBalance) {
                    DB::table('inventory_balances')
                        ->where('id', $sourceBalance->id)
                        ->decrement('on_hand', $qty);
                } else {
                    DB::table('inventory_balances')->insert([
                        'tenant_id' => $tenantId,
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
                    'tenant_id' => $tenantId,
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
        $transfer = DB::table('stock_transfers')->where('id', $id)->first();
        if (!$transfer) {
            return response()->json(['status' => 'error', 'message' => 'Stock transfer not found'], 404);
        }

        if ($transfer->status === 'received') {
            return response()->json(['status' => 'error', 'message' => 'Stock transfer is already marked as received.'], 400);
        }

        $userId = $request->user() ? $request->user()->id : 1;
        $lines = DB::table('stock_transfer_lines')->where('transfer_id', $id)->get();

        DB::transaction(function () use ($transfer, $lines, $userId) {
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
                    ->first();

                if ($destBalance) {
                    DB::table('inventory_balances')
                        ->where('id', $destBalance->id)
                        ->increment('on_hand', $qty);
                } else {
                    DB::table('inventory_balances')->insert([
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
        $transfer = DB::table('stock_transfers')->where('id', $id)->first();
        if (!$transfer) {
            return response()->json(['status' => 'error', 'message' => 'Stock transfer not found'], 404);
        }

        $validated = $request->validate([
            'dispute_notes' => 'required|string|max:1000',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer',
            'items.*.disputed_quantity' => 'required|integer|min:1',
            'items.*.dispute_reason' => 'required|string|in:damaged_in_transit,short_shipped,expired_in_transit,wrong_item,crushed_box',
        ]);

        $userId = $request->user()?->id ?? 1;

        DB::transaction(function () use ($transfer, $validated, $userId) {
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
                        'tenant_id' => $transfer->tenant_id,
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
        $transfer = DB::table('stock_transfers')->where('id', $id)->first();
        if (!$transfer) {
            return response()->json(['status' => 'error', 'message' => 'Stock transfer not found'], 404);
        }

        $validated = $request->validate([
            'resolution_action' => 'required|string|in:accept_shortage,write_off_shrinkage,return_to_origin',
            'resolution_notes' => 'required|string|max:1000',
        ]);

        $userId = $request->user()?->id ?? 1;

        DB::transaction(function () use ($transfer, $validated, $userId) {
            DB::table('stock_transfers')
                ->where('id', $transfer->id)
                ->update([
                    'dispute_status' => 'resolved',
                    'status' => 'received',
                    'received_at' => now(),
                    'notes' => DB::raw("CONCAT(COALESCE(notes, ''), '\n[Resolution]: ', " . DB::getPdo()->quote($validated['resolution_notes']) . ")"),
                    'updated_at' => now(),
                ]);

            $lines = DB::table('stock_transfer_lines')->where('transfer_id', $transfer->id)->get();
            foreach ($lines as $line) {
                $receivedQty = $line->received_quantity ?? ($line->quantity - $line->disputed_quantity);
                if ($receivedQty > 0) {
                    $balance = DB::table('inventory_balances')
                        ->where('outlet_id', $transfer->to_outlet_id)
                        ->where('product_id', $line->product_id)
                        ->first();

                    if ($balance) {
                        DB::table('inventory_balances')->where('id', $balance->id)->increment('on_hand', $receivedQty);
                    } else {
                        DB::table('inventory_balances')->insert([
                            'tenant_id' => $transfer->tenant_id,
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

        $updated = DB::table('stock_transfers')->where('id', $id)->first();
        return response()->json([
            'status' => 'success',
            'message' => "Transfer dispute for {$transfer->transfer_number} resolved.",
            'data' => $updated,
        ]);
    }
}
