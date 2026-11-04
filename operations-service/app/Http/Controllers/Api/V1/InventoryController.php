<?php

/**
 * Author: Antigravity Team
 * Date: 2026-11-04
 * Task: Missing UI Implementation (BD-0132)
 */

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Traits\ResolvesTenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * [BD-0011] Dual-Layer Multi-Warehouse Inventory & Movement Ledger Controller.
 *
 * @ticket BD-0011
 *
 * @component operations-service
 *
 * @file codebridge-services/operations-service/app/Http/Controllers/Api/V1/InventoryController.php
 *
 * @table inventory_balances, inventory_movements
 *
 * @entry-point GET /api/v1/inventory/balances, GET /api/v1/inventory/movements, POST /api/v1/inventory/adjust
 *
 * @date-time 2026-10-23T15:40:00+07:00
 *
 * Process Capabilities:
 * 1. Layer 1 (Snapshot): Real-time on_hand, reserved, and available stock levels.
 * 2. Layer 2 (Append-Only): Immutable movement ledger for receive, sale, transfer, and adjustment.
 * 3. Low stock threshold warnings and out-of-stock alerts.
 */
class InventoryController extends Controller
{
    use ResolvesTenantContext;

    public function balances(Request $request)
    {
        $tenantId = $this->getTenantId($request);
        $outletId = $request->query('outlet_id');
        $status = $request->query('status');
        $search = $request->query('q');

        $query = DB::table('products as p')
            ->leftJoin('inventory_balances as ib', function ($join) use ($outletId) {
                $join->on('p.id', '=', 'ib.product_id');
                if (! empty($outletId)) {
                    $join->where('ib.outlet_id', '=', $outletId);
                }
            })
            ->leftJoin('categories as c', 'p.category_id', '=', 'c.id')
            ->leftJoin('outlets as o', function ($join) {
                $join->on('ib.outlet_id', '=', 'o.id');
            })
            ->where('p.is_active', true);

        // Scope to tenant organization
        if (! empty($tenantId) && $tenantId !== 'all') {
            $hasTenantProducts = DB::table('products')
                ->where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->exists();

            if ($hasTenantProducts) {
                $query->where('p.tenant_id', $tenantId);
            } else {
                $query->where(function ($q) use ($tenantId) {
                    $q->where('p.tenant_id', $tenantId)
                        ->orWhereNull('p.tenant_id');
                });
            }
        }

        if (! empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('p.name', 'like', "%{$search}%")
                    ->orWhere('p.sku', 'like', "%{$search}%")
                    ->orWhere('p.barcode', 'like', "%{$search}%");
            });
        }

        if ($status === 'low_stock') {
            $query->whereRaw('COALESCE(p.min_reorder_point, 0) > 0 AND COALESCE(ib.on_hand, 0) <= p.min_reorder_point');
        } elseif ($status === 'out_of_stock') {
            $query->whereRaw('COALESCE(ib.on_hand, 0) <= 0');
        } elseif ($status === 'in_stock') {
            $query->whereRaw('COALESCE(ib.on_hand, 0) > 0');
        }

        $escapedOutletId = addslashes((string) ($outletId ?? '1'));
        $balances = $query->select(
            DB::raw('COALESCE(ib.id, p.id) as balance_id'),
            DB::raw("COALESCE(ib.outlet_id, '{$escapedOutletId}') as outlet_id"),
            DB::raw("COALESCE(o.name, 'Main Outlet') as outlet_name"),
            'p.id as product_id',
            'p.tenant_id',
            'p.name as product_name',
            'p.sku',
            'p.barcode',
            'p.selling_price as price',
            'p.cost_price',
            'p.image_url as image',
            'p.min_reorder_point',
            'c.name as category_name',
            DB::raw('COALESCE(ib.on_hand, 0) as on_hand'),
            DB::raw('COALESCE(ib.reserved, 0) as reserved'),
            DB::raw('(COALESCE(ib.on_hand, 0) - COALESCE(ib.reserved, 0)) as available'),
            DB::raw('CASE WHEN COALESCE(p.min_reorder_point, 0) > 0 AND COALESCE(ib.on_hand, 0) <= p.min_reorder_point THEN 1 ELSE 0 END as is_low_stock')
        )
            ->orderBy('p.name', 'asc')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $balances,
        ]);
    }

    public function movements(Request $request)
    {
        $tenantId = $this->getTenantId($request);
        $outletId = $request->query('outlet_id');
        $movementType = $request->query('type');
        $search = $request->query('q');

        $query = DB::table('inventory_movements as im')
            ->join('products as p', 'im.product_id', '=', 'p.id')
            ->join('outlets as o', 'im.outlet_id', '=', 'o.id')
            ->leftJoin('users as u', 'im.user_id', '=', 'u.id');

        // Scope to tenant organization
        if (! empty($tenantId) && $tenantId !== 'all') {
            $hasTenantMovements = DB::table('inventory_movements')->where('tenant_id', $tenantId)->exists();
            if ($hasTenantMovements) {
                $query->where('im.tenant_id', $tenantId);
            } else {
                $query->where(function ($q) use ($tenantId) {
                    $q->where('im.tenant_id', $tenantId)->orWhereNull('im.tenant_id');
                });
            }
        }

        if (! empty($outletId)) {
            $query->where('im.outlet_id', $outletId);
        }

        if (! empty($movementType)) {
            $query->where('im.movement_type', $movementType);
        }

        if (! empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('p.name', 'like', "%{$search}%")
                    ->orWhere('p.sku', 'like', "%{$search}%")
                    ->orWhere('im.reference_id', 'like', "%{$search}%")
                    ->orWhere('im.notes', 'like', "%{$search}%");
            });
        }

        $movements = $query->select(
            'im.id',
            'im.outlet_id',
            'o.name as outlet_name',
            'im.product_id',
            'p.name as product_name',
            'p.sku',
            'im.movement_type',
            'im.quantity',
            'im.unit_cost',
            'im.reference_type',
            'im.reference_id',
            'im.notes',
            'u.name as user_name',
            'im.created_at'
        )
            ->orderBy('im.created_at', 'desc')
            ->limit(100)
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $movements,
        ]);
    }

    public function receive(Request $request)
    {
        $tenantId = $this->getTenantId($request);
        $validated = $request->validate([
            'outlet_id' => 'required',
            'po_number' => 'nullable|string|max:100',
            'supplier_name' => 'nullable|string|max:255',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_cost' => 'nullable|numeric|min:0',
        ]);

        $userId = $request->user() ? $request->user()->id : 1;
        $outletId = $validated['outlet_id'];
        $poNumber = $validated['po_number'] ?? ('PO-'.strtoupper(substr(uniqid(), -6)));

        DB::transaction(function () use ($validated, $tenantId, $userId, $outletId, $poNumber) {
            foreach ($validated['items'] as $item) {
                $productId = $item['product_id'];
                $qty = $item['quantity'];
                $unitCost = $item['unit_cost'] ?? null;

                // Update or insert balance
                $balance = DB::table('inventory_balances')
                    ->where('outlet_id', $outletId)
                    ->where('product_id', $productId)
                    ->first();

                if ($balance) {
                    DB::table('inventory_balances')
                        ->where('id', $balance->id)
                        ->increment('on_hand', $qty);
                } else {
                    DB::table('inventory_balances')->insert([
                        'tenant_id' => $tenantId,
                        'outlet_id' => $outletId,
                        'product_id' => $productId,
                        'on_hand' => $qty,
                        'reserved' => 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                // Append to movements ledger
                DB::table('inventory_movements')->insert([
                    'tenant_id' => $tenantId,
                    'outlet_id' => $outletId,
                    'product_id' => $productId,
                    'user_id' => $userId,
                    'movement_type' => 'receive',
                    'quantity' => $qty,
                    'unit_cost' => $unitCost,
                    'reference_type' => 'purchase_order',
                    'reference_id' => $poNumber,
                    'notes' => 'Stock received from PO: '.$poNumber.($validated['supplier_name'] ? ' ('.$validated['supplier_name'].')' : ''),
                    'created_at' => now(),
                ]);
            }
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Stock received successfully and ledger updated.',
            'po_number' => $poNumber,
        ]);
    }

    public function adjust(Request $request)
    {
        $tenantId = $this->getTenantId($request);
        $validated = $request->validate([
            'outlet_id' => 'required',
            'product_id' => 'required',
            'quantity' => 'required|integer|min:1',
            'type' => 'required|string|in:increment,decrement',
            'reason' => 'required|string|in:spoilage,damaged,count_variance,found,other',
            'notes' => 'nullable|string|max:500',
        ]);

        $userId = $request->user() ? $request->user()->id : 1;
        $outletId = $validated['outlet_id'];
        $productId = $validated['product_id'];
        $qty = $validated['quantity'];
        $isIncrement = $validated['type'] === 'increment';
        $deltaQty = $isIncrement ? $qty : -$qty;

        DB::transaction(function () use ($validated, $tenantId, $userId, $outletId, $productId, $deltaQty) {
            $balance = DB::table('inventory_balances')
                ->where('outlet_id', $outletId)
                ->where('product_id', $productId)
                ->first();

            if ($balance) {
                if ($deltaQty >= 0) {
                    DB::table('inventory_balances')->where('id', $balance->id)->increment('on_hand', $deltaQty);
                } else {
                    DB::table('inventory_balances')->where('id', $balance->id)->decrement('on_hand', abs($deltaQty));
                }
            } else {
                DB::table('inventory_balances')->insert([
                    'tenant_id' => $tenantId,
                    'outlet_id' => $outletId,
                    'product_id' => $productId,
                    'on_hand' => max(0, $deltaQty),
                    'reserved' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Append to movements ledger
            DB::table('inventory_movements')->insert([
                'tenant_id' => $tenantId,
                'outlet_id' => $outletId,
                'product_id' => $productId,
                'user_id' => $userId,
                'movement_type' => 'adjustment',
                'quantity' => $deltaQty,
                'reference_type' => 'stock_adjustment',
                'reference_id' => 'ADJ-'.strtoupper(substr(uniqid(), -6)),
                'notes' => 'Adjustment ('.ucfirst($validated['reason']).'): '.($validated['notes'] ?? 'Manual stock count adjustment'),
                'created_at' => now(),
            ]);
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Stock adjustment recorded successfully.',
        ]);
    }

    public function expired(Request $request)
    {
        $outletId = $request->query('outlet_id', 1);
        $status = $request->query('status'); // 'expired', 'expiring_soon'
        $search = $request->query('q');

        $today = now()->toDateString();
        $soonThreshold = now()->addDays(30)->toDateString();

        $query = DB::table('products as p')
            ->leftJoin('inventory_balances as ib', function ($join) use ($outletId) {
                $join->on('p.id', '=', 'ib.product_id')
                    ->where('ib.outlet_id', '=', $outletId);
            })
            ->leftJoin('categories as c', 'p.category_id', '=', 'c.id')
            ->whereNotNull('p.expiry_date');

        if (! empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('p.name', 'like', "%{$search}%")
                    ->orWhere('p.sku', 'like', "%{$search}%");
            });
        }

        if ($status === 'expired') {
            $query->where('p.expiry_date', '<', $today);
        } elseif ($status === 'expiring_soon') {
            $query->whereBetween('p.expiry_date', [$today, $soonThreshold]);
        } else {
            $query->where('p.expiry_date', '<=', $soonThreshold);
        }

        $daysLeftSql = DB::getDriverName() === 'sqlite'
            ? "CAST(julianday(p.expiry_date) - julianday('now') AS INTEGER) as days_left"
            : 'DATEDIFF(p.expiry_date, CURDATE()) as days_left';

        $items = $query->select(
            'p.id as product_id',
            'p.name as product_name',
            'p.sku',
            'p.barcode',
            'p.selling_price as price',
            'p.cost_price',
            'p.image_url as image',
            'p.expiry_date',
            'c.name as category_name',
            DB::raw('COALESCE(ib.on_hand, 0) as on_hand'),
            DB::raw($daysLeftSql),
            DB::raw("CASE WHEN p.expiry_date < '{$today}' THEN 'expired' ELSE 'expiring_soon' END as status")
        )
            ->orderBy('p.expiry_date', 'asc')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $items,
        ]);
    }

    /**
     * [BD-0015 / Task #11] Real-time Multi-Outlet Stock Aggregation API
     */
    public function crossOutletStock(Request $request)
    {
        $productId = $request->query('product_id');
        $search = $request->query('q') ?? $request->query('search');
        $tenantId = $request->header('X-Tenant-Id') ?? $request->user()?->tenant_id;

        if (empty($productId) && empty($search)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Either product_id or search query parameter is required.',
            ], 400);
        }

        $productQuery = DB::table('products')->where('is_active', true);
        if (! empty($tenantId) && $tenantId !== 'all') {
            $productQuery->where(function ($q) use ($tenantId) {
                $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id');
            });
        }

        if (! empty($productId)) {
            $productQuery->where('id', $productId);
        } else {
            $productQuery->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%");
            });
        }

        $product = $productQuery->first();
        if (! $product) {
            return response()->json([
                'status' => 'error',
                'message' => 'Product not found.',
            ], 404);
        }

        // Fetch all active branch outlets with their respective inventory balance scoped by tenant
        $branchesQuery = DB::table('outlets as o')
            ->leftJoin('inventory_balances as ib', function ($join) use ($product) {
                $join->on('o.id', '=', 'ib.outlet_id')
                    ->where('ib.product_id', '=', $product->id);
            })
            ->select(
                'o.id as outlet_id',
                'o.name as outlet_name',
                'o.code as outlet_code',
                'o.address',
                'o.phone',
                DB::raw('COALESCE(ib.on_hand, 0) as on_hand'),
                DB::raw('COALESCE(ib.reserved, 0) as reserved'),
                DB::raw('GREATEST(0, COALESCE(ib.on_hand, 0) - COALESCE(ib.reserved, 0)) as available')
            )
            ->where('o.is_active', true);

        if (! empty($tenantId) && $tenantId !== 'all') {
            $branchesQuery->where(function ($q) use ($tenantId) {
                $q->where('o.tenant_id', $tenantId)->orWhereNull('o.tenant_id');
            });
        }

        $branches = $branchesQuery->orderBy('o.name', 'asc')->get();

        $totalCompanyOnHand = $branches->sum('on_hand');
        $totalCompanyAvailable = $branches->sum('available');

        return response()->json([
            'status' => 'success',
            'data' => [
                'product' => [
                    'id' => $product->id,
                    'name' => $product->name,
                    'sku' => $product->sku,
                    'barcode' => $product->barcode,
                    'selling_price' => $product->selling_price,
                ],
                'company_totals' => [
                    'on_hand' => $totalCompanyOnHand,
                    'available' => $totalCompanyAvailable,
                    'branch_count' => $branches->count(),
                ],
                'branches' => $branches,
            ],
        ]);
    }

    /**
     * Record internal supplies & packaging material operational consumption.
     * POST /api/v1/inventory/internal-consumption
     */
    public function internalConsumption(Request $request)
    {
        $tenantId = $this->getTenantId($request);
        $validated = $request->validate([
            'outlet_id' => 'required',
            'product_id' => 'required',
            'quantity' => 'required|integer|min:1',
            'category' => 'nullable|string|max:100',
            'reason' => 'nullable|string|max:200',
            'notes' => 'nullable|string|max:500',
        ]);

        $userId = $request->user() ? $request->user()->id : 1;
        $outletId = $validated['outlet_id'];
        $productId = $validated['product_id'];
        $qty = (int) $validated['quantity'];
        $category = $validated['category'] ?? 'packaging';
        $refId = 'EXP-SUP-'.strtoupper(substr(uniqid(), -6));

        DB::transaction(function () use ($validated, $tenantId, $userId, $outletId, $productId, $qty, $refId, $category) {
            $balance = DB::table('inventory_balances')
                ->where('outlet_id', $outletId)
                ->where('product_id', $productId)
                ->first();

            if ($balance) {
                DB::table('inventory_balances')->where('id', $balance->id)->decrement('on_hand', $qty);
            } else {
                DB::table('inventory_balances')->insert([
                    'tenant_id' => $tenantId,
                    'outlet_id' => $outletId,
                    'product_id' => $productId,
                    'on_hand' => 0,
                    'reserved' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('inventory_movements')->insert([
                'tenant_id' => $tenantId,
                'outlet_id' => $outletId,
                'product_id' => $productId,
                'user_id' => $userId,
                'movement_type' => 'internal_consumption',
                'quantity' => -$qty,
                'reference_type' => 'operational_supplies_expense',
                'reference_id' => $refId,
                'notes' => 'Internal Consumable ('.ucfirst($category).'): '.($validated['reason'] ?? 'Daily counter supplies').(!empty($validated['notes']) ? ' - '.$validated['notes'] : ''),
                'created_at' => now(),
            ]);
        });

        return response()->json([
            'status' => 'success',
            'message' => "Internal consumable expense of {$qty} units recorded successfully.",
            'data' => [
                'reference_id' => $refId,
                'quantity' => $qty,
                'category' => $category,
            ],
        ]);
    }

    /**
     * Get inventory items by Batch/Lot with First-Expired, First-Out (FEFO) prioritization.
     * GET /api/v1/inventory/batches
     */
    public function batches(Request $request)
    {
        $tenantId = $this->getTenantId($request);
        $outletId = $request->query('outlet_id') ?? ($request->user()?->outlet_id ?? 1);
        $search = $request->query('q') ?? $request->query('search');
        $status = $request->query('status');

        $today = now()->toDateString();
        $soonThreshold = now()->addDays(30)->toDateString();
        $urgentThreshold = now()->addDays(7)->toDateString();

        $query = DB::table('products as p')
            ->leftJoin('inventory_balances as ib', function ($join) use ($outletId) {
                $join->on('p.id', '=', 'ib.product_id')
                    ->where('ib.outlet_id', '=', $outletId);
            })
            ->leftJoin('categories as c', 'p.category_id', '=', 'c.id');

        if (! empty($tenantId) && $tenantId !== 'all') {
            $query->where(function ($q) use ($tenantId) {
                $q->where('p.tenant_id', $tenantId)->orWhereNull('p.tenant_id');
            });
        }

        if (! empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('p.name', 'like', "%{$search}%")
                    ->orWhere('p.sku', 'like', "%{$search}%")
                    ->orWhere('p.barcode', 'like', "%{$search}%");
            });
        }

        $daysLeftSql = DB::getDriverName() === 'sqlite'
            ? "CAST(julianday(COALESCE(p.expiry_date, date('now', '+90 days'))) - julianday('now') AS INTEGER) as days_left"
            : 'DATEDIFF(COALESCE(p.expiry_date, DATE_ADD(CURDATE(), INTERVAL 90 DAY)), CURDATE()) as days_left';

        $rawItems = $query->select(
            'p.id as product_id',
            'p.name as product_name',
            'p.sku',
            'p.barcode',
            'p.cost_price',
            'p.selling_price as price',
            'p.image_url as image',
            'p.expiry_date',
            'p.created_at',
            'c.name as category_name',
            DB::raw('COALESCE(ib.on_hand, 0) as on_hand'),
            DB::raw($daysLeftSql)
        )
            ->orderByRaw('CASE WHEN p.expiry_date IS NULL THEN 1 ELSE 0 END, p.expiry_date ASC')
            ->get();

        $batches = $rawItems->map(function ($item) use ($today, $urgentThreshold, $soonThreshold) {
            $expiry = $item->expiry_date;
            $daysLeft = (int) ($item->days_left ?? 90);

            if (! $expiry) {
                $fefoStatus = 'fresh';
                $badgeText = 'No Expiry Set';
            } elseif ($expiry < $today) {
                $fefoStatus = 'quarantined';
                $badgeText = 'Quarantined (Expired)';
            } elseif ($expiry <= $urgentThreshold) {
                $fefoStatus = 'urgent';
                $badgeText = 'Urgent Clearance';
            } elseif ($expiry <= $soonThreshold) {
                $fefoStatus = 'expiring_soon';
                $badgeText = 'FEFO Priority 1';
            } else {
                $fefoStatus = 'fresh';
                $badgeText = 'Fresh Stock';
            }

            $lotDate = $item->created_at ? date('Ymd', strtotime($item->created_at)) : date('Ymd');
            $cleanSku = preg_replace('/[^A-Za-z0-9]/', '', (string) ($item->sku ?: 'SKU'));
            $lotNumber = "LOT-{$lotDate}-{$cleanSku}-".str_pad((string) $item->product_id, 3, '0', STR_PAD_LEFT);

            return [
                'lot_number' => $lotNumber,
                'product_id' => $item->product_id,
                'product_name' => $item->product_name,
                'sku' => $item->sku,
                'barcode' => $item->barcode,
                'category_name' => $item->category_name,
                'on_hand' => (int) $item->on_hand,
                'cost_price' => (float) ($item->cost_price ?? 0),
                'selling_price' => (float) ($item->price ?? 0),
                'expiry_date' => $expiry,
                'days_left' => $daysLeft,
                'fefo_status' => $fefoStatus,
                'badge_text' => $badgeText,
            ];
        });

        if ($status && $status !== 'all') {
            $batches = $batches->filter(fn ($b) => $b['fefo_status'] === $status)->values();
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'outlet_id' => $outletId,
                'total_batches' => $batches->count(),
                'quarantined_count' => $batches->where('fefo_status', 'quarantined')->count(),
                'urgent_count' => $batches->where('fefo_status', 'urgent')->count(),
                'expiring_soon_count' => $batches->where('fefo_status', 'expiring_soon')->count(),
                'batches' => $batches,
            ],
        ]);
    }

}
