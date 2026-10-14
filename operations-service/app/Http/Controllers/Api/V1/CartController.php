<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

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
