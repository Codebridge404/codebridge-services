<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CouponController extends Controller
{
    /**
     * Validate Coupon Code & Compute Discount
     */
    public function validateCoupon(Request $request)
    {
        $validated = $request->validate([
            'code' => 'required|string',
            'subtotal' => 'required|numeric|min:0',
        ]);

        $code = strtoupper(trim($validated['code']));
        $subtotal = (float) $validated['subtotal'];

        // Auto-seed default coupons if table empty
        $this->ensureDefaultCouponsSeeded();

        $coupon = DB::table('coupons')
            ->where(DB::raw('UPPER(code)'), $code)
            ->where('is_active', true)
            ->first();

        if (!$coupon) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid or expired coupon code.',
            ], 404);
        }

        if (!empty($coupon->expires_at) && now()->gt($coupon->expires_at)) {
            return response()->json([
                'status' => 'error',
                'message' => 'This promo code has expired.',
            ], 422);
        }

        $minSpend = (float) ($coupon->min_spend ?? 0);
        if ($subtotal < $minSpend) {
            return response()->json([
                'status' => 'error',
                'message' => "Minimum purchase of \${$minSpend} required for promo code {$code}.",
            ], 422);
        }

        $discountValue = (float) $coupon->discount_value;
        $discountAmount = 0;

        if ($coupon->discount_type === 'percentage') {
            $discountAmount = round($subtotal * ($discountValue / 100), 2);
        } else {
            $discountAmount = min($subtotal, $discountValue);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Coupon code applied successfully.',
            'data' => [
                'code' => $coupon->code,
                'discount_type' => $coupon->discount_type,
                'discount_value' => $discountValue,
                'discount_amount' => $discountAmount,
                'new_subtotal' => max(0, $subtotal - $discountAmount),
            ],
        ]);
    }

    /**
     * Helper to seed default test coupons
     */
    protected function ensureDefaultCouponsSeeded()
    {
        if (DB::table('coupons')->count() === 0) {
            DB::table('coupons')->insert([
                [
                    'code' => 'WELCOME10',
                    'discount_type' => 'percentage',
                    'discount_value' => 10.00,
                    'min_spend' => 5.00,
                    'is_active' => true,
                    'expires_at' => now()->addYear(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'code' => 'FRESH5',
                    'discount_type' => 'fixed',
                    'discount_value' => 5.00,
                    'min_spend' => 15.00,
                    'is_active' => true,
                    'expires_at' => now()->addYear(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);
        }
    }
}
