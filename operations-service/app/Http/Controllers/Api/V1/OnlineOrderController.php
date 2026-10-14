<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Exception;

class OnlineOrderController extends Controller
{
    /**
     * Create Public Customer Online Order
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'customer_name' => 'required|string|max:255',
            'customer_phone' => 'required|string|max:50',
            'delivery_type' => 'required|string|in:pickup,delivery',
            'delivery_address' => 'nullable|string|max:500',
            'payment_method' => 'nullable|string|in:khqr,cash,store_credit',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required',
            'items.*.product_name' => 'required|string',
            'items.*.quantity' => 'required|numeric|min:1',
            'items.*.unit_price' => 'required|numeric|min:0',
        ]);

        return DB::transaction(function () use ($validated) {
            $orderNumber = 'ORD-ONLINE-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -4));

            $subtotal = 0;
            foreach ($validated['items'] as $item) {
                $qty = (float) $item['quantity'];
                $unitPrice = (float) $item['unit_price'];
                $subtotal += ($qty * $unitPrice);
            }

            $deliveryFee = $validated['delivery_type'] === 'delivery' ? 1.50 : 0.00;
            $taxAmount = round($subtotal * 0.10, 2);
            $grandTotal = $subtotal + $taxAmount + $deliveryFee;

            $orderId = DB::table('online_orders')->insertGetId([
                'order_number' => $orderNumber,
                'customer_name' => $validated['customer_name'],
                'customer_phone' => $validated['customer_phone'],
                'delivery_type' => $validated['delivery_type'],
                'delivery_address' => $validated['delivery_address'] ?? null,
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'delivery_fee' => $deliveryFee,
                'grand_total' => $grandTotal,
                'payment_status' => 'paid',
                'fulfillment_status' => 'confirmed',
                'payment_method' => $validated['payment_method'] ?? 'khqr',
                'reference_number' => 'PAY-' . strtoupper(substr(uniqid(), -6)),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($validated['items'] as $item) {
                $qty = (float) $item['quantity'];
                $unitPrice = (float) $item['unit_price'];
                $lineSubtotal = $qty * $unitPrice;

                DB::table('online_order_lines')->insert([
                    'online_order_id' => $orderId,
                    'product_id' => $item['product_id'],
                    'product_name' => $item['product_name'],
                    'quantity' => $qty,
                    'unit_price' => $unitPrice,
                    'subtotal' => $lineSubtotal,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Online order placed successfully.',
                'data' => [
                    'order_id' => $orderId,
                    'order_number' => $orderNumber,
                    'grand_total' => $grandTotal,
                    'fulfillment_status' => 'confirmed',
                ],
            ], 201);
        });
    }

    /**
     * Get list of online orders for admin fulfillment dashboard
     */
    public function index(Request $request)
    {
        $status = $request->query('status');

        $query = DB::table('online_orders')->orderBy('created_at', 'desc');

        if (!empty($status) && $status !== 'all') {
            $query->where('fulfillment_status', $status);
        }

        $orders = $query->get();

        // Attach lines for each order
        $orderIds = $orders->pluck('id');
        $lines = DB::table('online_order_lines')->whereIn('online_order_id', $orderIds)->get();
        $linesMap = $lines->groupBy('online_order_id');

        $result = $orders->map(function ($order) use ($linesMap) {
            $order->lines = $linesMap[$order->id] ?? [];
            return $order;
        });

        return response()->json([
            'status' => 'success',
            'data' => $result,
        ]);
    }

    /**
     * Update order fulfillment status (preparing -> ready -> completed)
     */
    public function updateStatus(Request $request, $id)
    {
        $validated = $request->validate([
            'fulfillment_status' => 'required|string|in:confirmed,preparing,ready,completed,cancelled',
        ]);

        $order = DB::table('online_orders')->where('id', $id)->first();
        if (!$order) {
            return response()->json([
                'status' => 'error',
                'message' => 'Order not found.',
            ], 404);
        }

        $updates = [
            'fulfillment_status' => $validated['fulfillment_status'],
            'updated_at' => now(),
        ];

        if ($validated['fulfillment_status'] === 'completed') {
            $updates['payment_status'] = 'paid';
        }

        DB::table('online_orders')->where('id', $id)->update($updates);

        return response()->json([
            'status' => 'success',
            'message' => 'Order fulfillment status updated to ' . $validated['fulfillment_status'] . '.',
        ]);
    }
}
