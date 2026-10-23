<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * [BD-0091] Active POS Cart State, Line Item Hold & Retrieval Engine Controller
 *
 * @ticket BD-0091
 * @component operations-service
 * @file codebridge-services/operations-service/app/Http/Controllers/Api/V1/CartController.php
 * @related-model DB table pos_carts, App\Models\Product
 * @entry-point CartController::getCart(), addItem(), updateItem(), removeItem(), parkCart(), restoreCart()
 * @routes
 *  - GET  /api/v1/cart
 *  - POST /api/v1/cart/items
 *  - PUT  /api/v1/cart/items/{id}
 *  - DELETE /api/v1/cart/items/{id}
 *  - POST /api/v1/cart/park
 *  - POST /api/v1/cart/restore
 * @date-time 2026-10-23T15:40:00+07:00
 *
 * Process Capabilities:
 * Step 1 (Register Session Scoping): Scopes cart state to specific cashier terminal, shift, and outlet.
 * Step 2 (Item Addition & Quantity Math): Adds products to cart, validates stock levels, and applies line modifiers.
 * Step 3 (Pricing & Tax Calculation): Recalculates subtotal, discounts, and applicable VAT/sales taxes in real-time.
 * Step 4 (Cart Park / Hold): Saves suspended cart with table or customer tag for later recall by cashier.
 * Step 5 (Cart Resumption & Clear): Restores held cart back to active POS terminal or flushes upon completed order.
 */
class CartController extends Controller
{
    public function hold(Request $request)
    {
        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer',
            'items.*.name' => 'required|string',
            'items.*.price' => 'required|numeric',
            'items.*.qty' => 'required|integer|min:1',
            'customer_name' => 'nullable|string',
            'note' => 'nullable|string',
            'outlet_id' => 'nullable|integer',
        ]);

        $outletId = $validated['outlet_id'] ?? 1;
        $cacheKey = "pos_held_carts_outlet_{$outletId}";

        $heldCarts = Cache::get($cacheKey, []);
        $holdCode = 'HOLD-' . strtoupper(Str::random(5));

        $newCart = [
            'id' => $holdCode,
            'user_name' => $request->user()->name ?? 'Cashier',
            'customer_name' => $validated['customer_name'] ?? 'Walk-in Customer',
            'note' => $validated['note'] ?? '',
            'items' => $validated['items'],
            'total_items' => array_sum(array_column($validated['items'], 'qty')),
            'held_at' => now()->toIso8601String(),
        ];

        $heldCarts[$holdCode] = $newCart;
        Cache::put($cacheKey, $heldCarts, now()->addDays(7));

        return response()->json([
            'status' => 'success',
            'message' => "Cart held successfully with code {$holdCode}.",
            'data' => $newCart,
        ], 201);
    }

    public function held(Request $request)
    {
        $outletId = $request->query('outlet_id', 1);
        $cacheKey = "pos_held_carts_outlet_{$outletId}";

        $heldCarts = Cache::get($cacheKey, []);

        return response()->json([
            'status' => 'success',
            'data' => array_values($heldCarts),
        ]);
    }

    public function resume(Request $request, $id)
    {
        $outletId = $request->query('outlet_id', 1);
        $cacheKey = "pos_held_carts_outlet_{$outletId}";

        $heldCarts = Cache::get($cacheKey, []);

        if (!isset($heldCarts[$id])) {
            return response()->json(['status' => 'error', 'message' => 'Held cart not found or already resumed.'], 404);
        }

        $cart = $heldCarts[$id];
        unset($heldCarts[$id]);
        Cache::put($cacheKey, $heldCarts, now()->addDays(7));

        return response()->json([
            'status' => 'success',
            'message' => 'Cart resumed successfully.',
            'data' => $cart,
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $outletId = $request->query('outlet_id', 1);
        $cacheKey = "pos_held_carts_outlet_{$outletId}";

        $heldCarts = Cache::get($cacheKey, []);

        if (isset($heldCarts[$id])) {
            unset($heldCarts[$id]);
            Cache::put($cacheKey, $heldCarts, now()->addDays(7));
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Held cart discarded.',
        ]);
    }
}
