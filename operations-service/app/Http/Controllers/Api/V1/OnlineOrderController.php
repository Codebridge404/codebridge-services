<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\OnlineOrder\StoreOnlineOrderRequest;
use App\Http\Requests\OnlineOrder\UpdateOnlineOrderStatusRequest;
use App\Traits\ResolvesTenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * [BD-0098] Omnichannel E-Commerce & Third-party Delivery Ingestion Controller
 *
 * @ticket BD-0098
 * @component operations-service
 * @file codebridge-services/operations-service/app/Http/Controllers/Api/V1/OnlineOrderController.php
 * @related-model App\Models\Order, DB table online_orders
 * @entry-point OnlineOrderController::index(), store(), updateStatus()
 * @date-time 2026-10-23T15:40:00+07:00
 */
class OnlineOrderController extends Controller
{
    use ResolvesTenantContext;

    /**
     * Create Public Customer Online Order (recomputing authoritative prices & tenant scope from DB catalog)
     */
    public function store(StoreOnlineOrderRequest $request)
    {
        $requestedTenantId = $this->getTenantId($request, false);
        $validated = $request->validated();

        $productIds = array_values(array_unique(array_map(
            fn ($item) => (int) $item['product_id'],
            $validated['items']
        )));

        $products = DB::table('products')
            ->whereIn('id', $productIds)
            ->where('is_active', true)
            ->get()
            ->keyBy('id');

        if ($products->count() !== count($productIds)) {
            return response()->json([
                'status' => 'error',
                'message' => 'One or more ordered products are invalid or inactive.',
            ], 422);
        }

        // Enforce single-tenant consistency across ordered items & optional request header/query
        $productTenants = $products->pluck('tenant_id')
            ->filter(fn ($tid) => $tid !== null && $tid !== '')
            ->map(fn ($tid) => (string) $tid)
            ->unique()
            ->values();

        if ($productTenants->count() > 1) {
            return response()->json([
                'status' => 'error',
                'message' => 'Online order items cannot mix products from multiple organizations.',
            ], 403);
        }

        $resolvedTenantId = $productTenants->first();

        if ($requestedTenantId !== null && $requestedTenantId !== 'all') {
            if ($resolvedTenantId !== null && (string) $resolvedTenantId !== (string) $requestedTenantId) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Unauthorized: Ordered product belongs to another organization.',
                ], 403);
            }
            $resolvedTenantId = $resolvedTenantId ?? (string) $requestedTenantId;
        }

        return DB::transaction(function () use ($validated, $products, $resolvedTenantId) {
            $orderNumber = 'ORD-ONLINE-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -4));

            $subtotal = 0.0;
            $linePayloads = [];

            foreach ($validated['items'] as $item) {
                $product = $products[(int) $item['product_id']];
                $qty = (float) $item['quantity'];
                // Always trust DB selling_price, never client unit_price
                $unitPrice = (float) $product->selling_price;
                $lineSubtotal = round($qty * $unitPrice, 2);
                $subtotal += $lineSubtotal;

                $linePayloads[] = [
                    'tenant_id' => $resolvedTenantId,
                    'product_id' => (int) $product->id,
                    'product_name' => (string) $product->name,
                    'quantity' => $qty,
                    'unit_price' => $unitPrice,
                    'subtotal' => $lineSubtotal,
                ];
            }

            $deliveryFee = $validated['delivery_type'] === 'delivery' ? 1.50 : 0.00;
            $taxAmount = round($subtotal * 0.10, 2);
            $grandTotal = round($subtotal + $taxAmount + $deliveryFee, 2);

            $orderId = DB::table('online_orders')->insertGetId([
                'tenant_id' => $resolvedTenantId,
                'order_number' => $orderNumber,
                'customer_name' => $validated['customer_name'],
                'customer_phone' => $validated['customer_phone'],
                'delivery_type' => $validated['delivery_type'],
                'delivery_address' => $validated['delivery_address'] ?? null,
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'delivery_fee' => $deliveryFee,
                'grand_total' => $grandTotal,
                'payment_status' => 'pending',
                'fulfillment_status' => 'pending',
                'payment_method' => $validated['payment_method'] ?? 'khqr',
                'reference_number' => 'PAY-' . strtoupper(substr(uniqid(), -6)),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $now = now();
            foreach ($linePayloads as $line) {
                $line['online_order_id'] = $orderId;
                $line['created_at'] = $now;
                $line['updated_at'] = $now;
                DB::table('online_order_lines')->insert($line);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Online order placed successfully.',
                'data' => [
                    'order_id' => $orderId,
                    'order_number' => $orderNumber,
                    'subtotal' => $subtotal,
                    'tax_amount' => $taxAmount,
                    'delivery_fee' => $deliveryFee,
                    'grand_total' => $grandTotal,
                    'payment_status' => 'pending',
                    'fulfillment_status' => 'pending',
                ],
            ], 201);
        });
    }

    /**
     * Get list of online orders for admin fulfillment dashboard
     */
    public function index(Request $request)
    {
        $tenantId = $this->getTenantId($request, true);
        $status = $request->query('status');
        $perPage = max(1, min(100, (int) $request->query('per_page', 50)));

        $query = DB::table('online_orders')->orderBy('created_at', 'desc');

        if ($tenantId !== 'all') {
            $query->where('tenant_id', $tenantId);
        }

        if (!empty($status) && $status !== 'all') {
            $query->where('fulfillment_status', $status);
        }

        $paginator = $query->paginate($perPage);
        $orders = collect($paginator->items());

        // Attach lines for each order
        $orderIds = $orders->pluck('id');
        $lines = $orderIds->isEmpty()
            ? collect()
            : DB::table('online_order_lines')->whereIn('online_order_id', $orderIds)->get();
        $linesMap = $lines->groupBy('online_order_id');

        $result = $orders->map(function ($order) use ($linesMap) {
            $order->lines = $linesMap[$order->id] ?? [];
            return $order;
        })->values();

        return response()->json([
            'status' => 'success',
            'data' => $result,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * Update order fulfillment status (pending -> confirmed -> preparing -> ready -> completed)
     */
    public function updateStatus(UpdateOnlineOrderStatusRequest $request, $id)
    {
        $this->getTenantId($request, true);
        $validated = $request->validated();

        $order = DB::table('online_orders')->where('id', $id)->first();
        if (!$order) {
            return response()->json([
                'status' => 'error',
                'message' => 'Order not found.',
            ], 404);
        }

        $this->authorizeTenantResource($order->tenant_id, $request, 'OnlineOrder');

        $updates = [
            'fulfillment_status' => $validated['fulfillment_status'],
            'updated_at' => now(),
        ];

        // Do not flip digital (KHQR) payment_status to paid just by completing fulfillment;
        // only cash-on-delivery orders collected upon completion settle here.
        if ($validated['fulfillment_status'] === 'completed' && ($order->payment_method ?? '') === 'cash' && $order->payment_status !== 'paid') {
            $updates['payment_status'] = 'paid';
        }

        DB::table('online_orders')->where('id', $id)->update($updates);

        return response()->json([
            'status' => 'success',
            'message' => 'Order fulfillment status updated to ' . $validated['fulfillment_status'] . '.',
        ]);
    }
}
