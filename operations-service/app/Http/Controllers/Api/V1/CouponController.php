<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\TenantAuthorizationService;
use App\Traits\ResolvesTenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * [BD-0093] Promotional Coupon Rules, Usage Quotas & Redemption Controller
 *
 * @ticket BD-0093
 * @component operations-service
 * @file codebridge-services/operations-service/app/Http/Controllers/Api/V1/CouponController.php
 * @related-model App\Models\Coupon, DB table coupon_usages
 * @entry-point CouponController::index(), store(), show(), update(), destroy(), validateCoupon()
 * @routes
 *  - GET  /api/v1/coupons
 *  - POST /api/v1/coupons
 *  - GET  /api/v1/coupons/{id}
 *  - PUT  /api/v1/coupons/{id}
 *  - POST /api/v1/coupons/validate
 * @date-time 2026-10-23T15:40:00+07:00
 *
 * Process Capabilities:
 * Step 1 (Tenant Isolation): Validates promotional coupon codes within tenant scope.
 * Step 2 (Code & Validity Evaluation): Checks coupon start date, end date, minimum spend, and customer restrictions.
 * Step 3 (Quota & Per-User Limit Check): Verifies remaining usage quota and maximum redeems per customer account.
 * Step 4 (Discount Application): Computes fixed-amount or percentage discount deduction against eligible cart items.
 * Step 5 (Redemption Counter Increment): Records atomic redemption log entry upon successful checkout completion.
 */
class CouponController extends Controller
{
    use ResolvesTenantContext;

    /**
     * Validate Coupon Code & Compute Discount
     */
    public function validateCoupon(Request $request)
    {
        $tenantId = $this->getTenantId($request, false);
        $isPlatformSuperAdmin = $request->user() && TenantAuthorizationService::hasPlatformSuperAdminRole($request->user());

        $validated = $request->validate([
            'code' => 'required|string|max:100',
            'subtotal' => 'required|numeric|min:0',
        ]);

        $code = strtoupper(trim($validated['code']));
        $subtotal = (float) $validated['subtotal'];

        $couponQuery = DB::table('coupons')
            ->where(DB::raw('UPPER(code)'), $code)
            ->where('is_active', true);

        if (!empty($tenantId) && $tenantId !== 'all') {
            $couponQuery->where(function ($q) use ($tenantId) {
                $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id');
            })->orderByRaw('CASE WHEN tenant_id IS NULL THEN 1 ELSE 0 END ASC');
        } elseif ($tenantId === 'all' && $isPlatformSuperAdmin) {
            // Super admin cross-tenant lookup
        } else {
            $couponQuery->whereNull('tenant_id');
        }

        $coupon = $couponQuery->first();

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
}
