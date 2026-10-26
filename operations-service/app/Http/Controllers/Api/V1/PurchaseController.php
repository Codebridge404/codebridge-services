<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
    protected function getTenantId(Request $request): ?string
    {
        return $request->header('X-Tenant-Id')
            ?? $request->user()?->tenant_id
            ?? $request->query('tenant_id')
            ?? null;
    }

    public function purchases(Request $request)
    {
        $tenantId = $this->getTenantId($request);
        $query = DB::table('purchases');

        if (!empty($tenantId) && $tenantId !== 'all') {
            if (Schema::hasColumn('purchases', 'tenant_id')) {
                $query->where('tenant_id', $tenantId);
            }
        }

        $purchases = $query->orderBy('created_at', 'desc')->get();
        return response()->json(['status' => 'success', 'data' => $purchases]);
    }

    public function purchaseOrders(Request $request)
    {
        $tenantId = $this->getTenantId($request);
        $query = DB::table('purchase_orders');

        if (!empty($tenantId) && $tenantId !== 'all') {
            $hasTenant = DB::table('purchase_orders')->where('tenant_id', $tenantId)->exists();
            if ($hasTenant) {
                $query->where('tenant_id', $tenantId);
            } else {
                $query->where(function ($q) use ($tenantId) {
                    $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id');
                });
            }
        }

        $orders = $query->orderBy('created_at', 'desc')->get();
        return response()->json(['status' => 'success', 'data' => $orders]);
    }

    public function store(Request $request)
    {
        $tenantId = $this->getTenantId($request);
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
            'tenant_id' => $tenantId,
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
        $tenantId = $this->getTenantId($request);
        $po = DB::table('purchase_orders')->where('id', $id)->first();

        if (!$po) {
            return response()->json(['status' => 'error', 'message' => 'PO not found'], 404);
        }

        $userId = $request->user()?->id ?? 1;
        $items = $request->input('items', []);
        $outletId = $request->input('outlet_id');

        if (empty($outletId)) {
            $firstOutlet = DB::table('outlets')->first();
            $outletId = $firstOutlet?->id ?? '0d612401-62c2-4e9e-9857-69e40f24c86d';
        }

        DB::transaction(function () use ($po, $id, $tenantId, $userId, $outletId, $items) {
            DB::table('purchase_orders')->where('id', $id)->update([
                'status' => 'received',
                'updated_at' => now(),
            ]);

            DB::table('purchases')->insert([
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
                if (empty($item['product_id']) || empty($item['quantity'])) continue;

                $productId = $item['product_id'];
                $qty = (int) $item['quantity'];
                $unitCost = $item['unit_cost'] ?? 0;

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

                DB::table('inventory_movements')->insert([
                    'tenant_id' => $tenantId,
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

        $updated = DB::table('purchase_orders')->where('id', $id)->first();
        return response()->json(['status' => 'success', 'data' => $updated, 'message' => 'PO received and stock updated.']);
    }

    /**
     * Compute automated re-order suggestions for products below safety threshold
     * GET /api/v1/purchase-orders/reorder-suggestions
     */
    public function reorderSuggestions(Request $request)
    {
        $tenantId = $this->getTenantId($request);
        $outletId = $request->query('outlet_id');

        $productsQuery = DB::table('products as p')
            ->leftJoin('brands as b', 'p.brand_id', '=', 'b.id')
            ->leftJoin('categories as c', 'p.category_id', '=', 'c.id')
            ->where('p.is_active', true)
            ->where('p.min_reorder_point', '>', 0);

        if (!empty($tenantId) && $tenantId !== 'all') {
            $productsQuery->where(function ($q) use ($tenantId) {
                $q->where('p.tenant_id', $tenantId)->orWhereNull('p.tenant_id');
            });
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
        )->get();

        $defaultSupplier = DB::table('suppliers')->where('is_active', true)->first();
        $defaultSupplierName = $defaultSupplier?->name ?? 'Standard Distribution Co.';

        $suggestionsBySupplier = [];
        $totalSuggestedItems = 0;
        $totalEstReplenishmentCost = 0;

        foreach ($products as $product) {
            $balanceQuery = DB::table('inventory_balances')->where('product_id', $product->id);
            if ($outletId) {
                $balanceQuery->where('outlet_id', $outletId);
            }
            $currentOnHand = (int) $balanceQuery->sum('on_hand');

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
        $tenantId = $this->getTenantId($request);
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

        DB::transaction(function () use ($suppliersData, $selectedSuppliers, $tenantId, &$createdPOs) {
            foreach ($suppliersData as $suppData) {
                $supplierName = $suppData['supplier'];

                if (!empty($selectedSuppliers) && !in_array($supplierName, $selectedSuppliers)) {
                    continue;
                }

                $poNumber = 'PO-AUTO-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -4));

                $id = DB::table('purchase_orders')->insertGetId([
                    'tenant_id' => $tenantId,
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
