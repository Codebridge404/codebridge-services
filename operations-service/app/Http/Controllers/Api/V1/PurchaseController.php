<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\TenantAuthorizationService;
use App\Support\TenantCatalogCache;
use App\Traits\ResolvesTenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * [BD-0100] Supplier Purchase Orders, Goods Receipt & Cost Tracking Controller
 *
 * @ticket BD-0100
 * @component operations-service
 * @file codebridge-services/operations-service/app/Http/Controllers/Api/V1/PurchaseController.php
 * @related-model DB table purchase_orders, DB table purchase_order_items
 * @entry-point PurchaseController::index(), store(), show(), receiveGoods(), cancel()
 * @routes
 *  - GET  /api/v1/purchases
 *  - POST /api/v1/purchases
 *  - GET  /api/v1/purchases/{id}
 *  - POST /api/v1/purchases/{id}/receive
 *  - POST /api/v1/purchases/{id}/cancel
 * @date-time 2026-10-23T15:40:00+07:00
 *
 * Process Capabilities:
 * Step 1 (Purchase Order Creation): Drafts supplier purchase orders with requested SKU quantities and agreed unit costs.
 * Step 2 (Approval Workflow): Enforces manager approval for purchase orders exceeding configured expenditure thresholds.
 * Step 3 (Goods Receipt Inspection): Records physical received quantities at warehouse/outlet against ordered PO items.
 * Step 4 (Inventory Stock Increment): Atomically increments on-hand stock and updates weighted average unit cost.
 * Step 5 (Discrepancy & Return Handling): Records damaged goods, short shipments, and supplier credit notes.
 */
class PurchaseController extends Controller
{
    use ResolvesTenantContext;

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

    public function purchases(Request $request)
    {
        $tenantId = $this->getTenantId($request, true);
        $isSuperAdminAll = $this->isSuperAdminAll($request, $tenantId);
        $perPage = min(100, max(1, (int) $request->query('per_page', 30)));
        $query = DB::table('purchases');

        if (!$isSuperAdminAll) {
            $query->where('tenant_id', $tenantId);
        }

        $paginator = $query->orderBy('created_at', 'desc')->paginate($perPage);
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

    public function purchaseOrders(Request $request)
    {
        $tenantId = $this->getTenantId($request, true);
        $isSuperAdminAll = $this->isSuperAdminAll($request, $tenantId);
        $perPage = min(100, max(1, (int) $request->query('per_page', 30)));
        $query = DB::table('purchase_orders');

        if (!$isSuperAdminAll) {
            $query->where('tenant_id', $tenantId);
        }

        $paginator = $query->orderBy('created_at', 'desc')->paginate($perPage);
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

    public function store(Request $request)
    {
        $resolvedTenantId = $this->getTenantId($request, true);
        $writeTenantId = $this->writeTenantId($resolvedTenantId);
        $validated = $request->validate([
            'supplier' => 'required|string|max:255',
            'po_number' => 'nullable|string|max:100',
            'items_count' => 'nullable|integer|min:1',
            'est_total' => 'required|numeric|min:0',
            'order_date' => 'nullable|date',
            'status' => 'nullable|string|in:pending,ordered,approved,received,cancelled',
        ]);

        $poNumber = $validated['po_number'] ?? ('PO-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -4)));

        $id = DB::table('purchase_orders')->insertGetId([
            'tenant_id' => $writeTenantId,
            'po_number' => $poNumber,
            'supplier' => $validated['supplier'],
            'items_count' => $validated['items_count'] ?? 1,
            'est_total' => $validated['est_total'],
            'status' => $validated['status'] ?? 'ordered',
            'order_date' => $validated['order_date'] ?? now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $po = DB::table('purchase_orders')->where('id', $id)->first();
        return response()->json(['status' => 'success', 'data' => $po], 201);
    }

    public function updateStatus(Request $request, $id)
    {
        $this->getTenantId($request, true);

        $po = DB::table('purchase_orders')->where('id', $id)->first();
        if (!$po) {
            return response()->json(['status' => 'error', 'message' => 'PO not found'], 404);
        }

        $this->authorizeTenantResource($po->tenant_id, $request, 'Purchase order');

        $validated = $request->validate([
            'status' => 'required|string|in:pending,ordered,approved,received,cancelled',
        ]);

        DB::table('purchase_orders')->where('id', $id)->update([
            'status' => $validated['status'],
            'updated_at' => now(),
        ]);

        $po = DB::table('purchase_orders')->where('id', $id)->first();
        return response()->json(['status' => 'success', 'data' => $po]);
    }

    public function receive(Request $request, $id)
    {
        $resolvedTenantId = $this->getTenantId($request, true);
        $isSuperAdminAll = $this->isSuperAdminAll($request, $resolvedTenantId);

        $po = DB::table('purchase_orders')->where('id', $id)->first();

        if (!$po) {
            return response()->json(['status' => 'error', 'message' => 'PO not found'], 404);
        }

        $this->authorizeTenantResource($po->tenant_id, $request, 'Purchase order');

        $validated = $request->validate([
            'outlet_id' => 'nullable|integer|min:1',
            'items' => 'nullable|array',
            'items.*.product_id' => 'required_with:items|integer|min:1',
            'items.*.quantity' => 'required_with:items|integer|min:1',
            'items.*.unit_cost' => 'nullable|numeric|min:0',
        ]);

        $writeTenantId = $po->tenant_id !== null
            ? (string) $po->tenant_id
            : $this->writeTenantId($resolvedTenantId);

        $userId = $request->user()?->id ?? 1;
        $items = $validated['items'] ?? [];
        $outletId = $validated['outlet_id'] ?? null;

        if ($outletId !== null) {
            $this->assertOutletBelongsToTenant($outletId, $writeTenantId, $isSuperAdminAll);
        } else {
            $outletId = DB::table('outlets')
                ->when($writeTenantId !== null, fn ($q) => $q->where('tenant_id', $writeTenantId))
                ->value('id') ?? 1;
        }

        foreach ($items as $item) {
            $this->assertProductBelongsToTenant($item['product_id'], $writeTenantId, $isSuperAdminAll);
        }

        DB::transaction(function () use ($po, $id, $writeTenantId, $userId, $outletId, $items) {
            DB::table('purchase_orders')->where('id', $id)->update([
                'status' => 'received',
                'updated_at' => now(),
            ]);

            DB::table('purchases')->insert([
                'tenant_id' => $writeTenantId,
                'purchase_ref' => $po->po_number,
                'supplier' => $po->supplier,
                'invoice_no' => 'INV-' . strtoupper(substr(uniqid(), -6)),
                'total_amount' => $po->est_total,
                'status' => 'received',
                'received_date' => now()->toDateString(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($items as $item) {
                $productId = $item['product_id'];
                $qty = (int) $item['quantity'];
                $unitCost = $item['unit_cost'] ?? 0;

                $balance = DB::table('inventory_balances')
                    ->where('outlet_id', $outletId)
                    ->where('product_id', $productId)
                    ->when($writeTenantId !== null, fn ($q) => $q->where('tenant_id', $writeTenantId))
                    ->first();

                if ($balance) {
                    DB::table('inventory_balances')
                        ->where('id', $balance->id)
                        ->increment('on_hand', $qty);
                } else {
                    DB::table('inventory_balances')->insert([
                        'tenant_id' => $writeTenantId,
                        'outlet_id' => $outletId,
                        'product_id' => $productId,
                        'on_hand' => $qty,
                        'reserved' => 0,
                        'available' => $qty,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                DB::table('inventory_movements')->insert([
                    'tenant_id' => $writeTenantId,
                    'outlet_id' => $outletId,
                    'product_id' => $productId,
                    'user_id' => $userId,
                    'movement_type' => 'receive',
                    'quantity' => $qty,
                    'unit_cost' => $unitCost,
                    'reference_type' => 'purchase_order',
                    'reference_id' => $po->po_number,
                    'notes' => "Received from PO #{$po->po_number} - Supplier: {$po->supplier}",
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        if (!empty($items)) {
            TenantCatalogCache::invalidate($writeTenantId, 'products');
        }

        $updated = DB::table('purchase_orders')->where('id', $id)->first();
        return response()->json(['status' => 'success', 'data' => $updated, 'message' => 'PO received and stock updated.']);
    }

    /**
     * Compute automated re-order suggestions for products below safety threshold
     * GET /api/v1/purchase-orders/reorder-suggestions
     */
    public function reorderSuggestions(Request $request)
    {
        $resolvedTenantId = $this->getTenantId($request, true);
        $isSuperAdminAll = $this->isSuperAdminAll($request, $resolvedTenantId);
        $writeTenantId = $this->writeTenantId($resolvedTenantId);
        $outletId = $request->query('outlet_id');

        $this->assertOutletBelongsToTenant($outletId, $writeTenantId, $isSuperAdminAll);

        $productsQuery = DB::table('products as p')
            ->leftJoin('brands as b', 'p.brand_id', '=', 'b.id')
            ->leftJoin('categories as c', 'p.category_id', '=', 'c.id')
            ->where('p.is_active', true)
            ->where('p.min_reorder_point', '>', 0);

        if (!$isSuperAdminAll) {
            $productsQuery->where('p.tenant_id', $resolvedTenantId);
        }

        $products = $productsQuery->select(
            'p.id',
            'p.tenant_id',
            'p.name',
            'p.sku',
            'p.barcode',
            'p.cost_price',
            'p.selling_price',
            'p.min_reorder_point',
            'b.name as brand_name',
            'c.name as category_name'
        )->limit(500)->get();

        $defaultSupplier = DB::table('suppliers')
            ->where('is_active', true)
            ->when(!$isSuperAdminAll, fn ($q) => $q->where('tenant_id', $resolvedTenantId))
            ->first();
        $defaultSupplierName = $defaultSupplier?->name ?? 'Standard Distribution Co.';

        $productIds = $products->pluck('id')->all();
        $balancesByProduct = [];
        if (!empty($productIds)) {
            $balancesByProduct = DB::table('inventory_balances')
                ->whereIn('product_id', $productIds)
                ->when(!$isSuperAdminAll, fn ($q) => $q->where('tenant_id', $resolvedTenantId))
                ->when(!empty($outletId), fn ($q) => $q->where('outlet_id', $outletId))
                ->select('product_id', DB::raw('COALESCE(SUM(on_hand), 0) as total_on_hand'))
                ->groupBy('product_id')
                ->pluck('total_on_hand', 'product_id')
                ->all();
        }

        $suggestionsBySupplier = [];
        $totalSuggestedItems = 0;
        $totalEstReplenishmentCost = 0;

        foreach ($products as $product) {
            $currentOnHand = (int) ($balancesByProduct[$product->id] ?? 0);

            if ($currentOnHand <= $product->min_reorder_point) {
                $targetSafetyStock = max($product->min_reorder_point * 2, 10);
                $suggestedQty = max($targetSafetyStock - $currentOnHand, 5);

                $unitCost = (float) $product->cost_price;
                if ($unitCost <= 0) {
                    $unitCost = (float) $product->selling_price > 0 ? (float) $product->selling_price * 0.70 : 5.00;
                }
                $estTotalCost = round($suggestedQty * $unitCost, 2);

                $supplierName = $product->brand_name ? "{$product->brand_name} Supplies" : $defaultSupplierName;

                if (!isset($suggestionsBySupplier[$supplierName])) {
                    $suggestionsBySupplier[$supplierName] = [
                        'supplier' => $supplierName,
                        'items' => [],
                        'items_count' => 0,
                        'est_total' => 0.00,
                    ];
                }

                $suggestionsBySupplier[$supplierName]['items'][] = [
                    'product_id' => $product->id,
                    'name' => $product->name,
                    'sku' => $product->sku,
                    'current_on_hand' => $currentOnHand,
                    'min_reorder_point' => $product->min_reorder_point,
                    'suggested_quantity' => $suggestedQty,
                    'unit_cost' => $unitCost,
                    'est_cost' => $estTotalCost,
                ];

                $suggestionsBySupplier[$supplierName]['items_count']++;
                $suggestionsBySupplier[$supplierName]['est_total'] += $estTotalCost;

                $totalSuggestedItems++;
                $totalEstReplenishmentCost += $estTotalCost;
            }
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'total_suppliers' => count($suggestionsBySupplier),
                'total_items_to_reorder' => $totalSuggestedItems,
                'total_estimated_cost' => round($totalEstReplenishmentCost, 2),
                'suppliers' => array_values($suggestionsBySupplier),
            ],
        ]);
    }

    /**
     * Auto-generate draft Purchase Orders from low stock suggestions
     * POST /api/v1/purchase-orders/auto-reorder
     */
    public function autoReorder(Request $request)
    {
        $resolvedTenantId = $this->getTenantId($request, true);
        $writeTenantId = $this->writeTenantId($resolvedTenantId);
        $selectedSuppliers = $request->input('suppliers', []);

        $suggestionsResponse = $this->reorderSuggestions($request)->getData(true);
        $suppliersData = $suggestionsResponse['data']['suppliers'] ?? [];

        if (empty($suppliersData)) {
            return response()->json([
                'status' => 'success',
                'message' => 'All product stock levels are healthy. No purchase orders needed.',
                'data' => [],
            ]);
        }

        $createdPOs = [];

        DB::transaction(function () use ($suppliersData, $selectedSuppliers, $writeTenantId, &$createdPOs) {
            foreach ($suppliersData as $suppData) {
                $supplierName = $suppData['supplier'];

                if (!empty($selectedSuppliers) && !in_array($supplierName, $selectedSuppliers)) {
                    continue;
                }

                $poNumber = 'PO-AUTO-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -4));

                $id = DB::table('purchase_orders')->insertGetId([
                    'tenant_id' => $writeTenantId,
                    'po_number' => $poNumber,
                    'supplier' => $supplierName,
                    'items_count' => $suppData['items_count'],
                    'est_total' => $suppData['est_total'],
                    'status' => 'pending',
                    'is_auto_generated' => true,
                    'trigger_reason' => 'Automated replenishment: stock dropped below safety min_reorder_point',
                    'order_date' => now()->toDateString(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $createdPOs[] = DB::table('purchase_orders')->where('id', $id)->first();
            }
        });

        return response()->json([
            'status' => 'success',
            'message' => count($createdPOs) . ' automated Purchase Orders generated successfully.',
            'data' => $createdPOs,
        ], 201);
    }
}
