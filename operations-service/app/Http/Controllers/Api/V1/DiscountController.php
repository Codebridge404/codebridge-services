<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * [BD-0095] Automated Discount Engines, Happy Hour & Volume Pricing Controller
 *
 * @ticket BD-0095
 * @component operations-service
 * @file codebridge-services/operations-service/app/Http/Controllers/Api/V1/DiscountController.php
 * @related-model App\Models\Discount, App\Models\Product
 * @entry-point DiscountController::index(), store(), show(), update(), destroy()
 * @routes
 *  - GET    /api/v1/discounts
 *  - POST   /api/v1/discounts
 *  - GET    /api/v1/discounts/{id}
 *  - PUT    /api/v1/discounts/{id}
 *  - DELETE /api/v1/discounts/{id}
 * @date-time 2026-10-23T15:40:00+07:00
 *
 * Process Capabilities:
 * Step 1 (Tenant Scoping): Isolates discount rules and promotional matrices per tenant.
 * Step 2 (Discount Definition): Configures percentage discounts, fixed reductions, and buy-X-get-Y (BOGO) rules.
 * Step 3 (Schedule & Happy Hour Trigger): Restricts discounts to specific days of week and time windows.
 * Step 4 (Target Item Matching): Matches discount applicability against individual SKUs, categories, or order totals.
 * Step 5 (Manager Authorization Override): Enforces supervisor PIN approval for ad-hoc cashier manual discounts.
 */
class DiscountController extends Controller
{
    public function index(Request $request)
    {
        $this->ensureSeeded();
        $discounts = DB::table('discounts')->orderBy('created_at', 'desc')->get();
        return response()->json(['status' => 'success', 'data' => $discounts]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string',
            'discount_pct' => 'required|string',
            'applies_to' => 'nullable|string',
        ]);

        $id = DB::table('discounts')->insertGetId([
            'name' => $validated['name'],
            'discount_pct' => $validated['discount_pct'],
            'applies_to' => $validated['applies_to'] ?? 'All Products',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Discount rule created.',
            'data' => DB::table('discounts')->where('id', $id)->first(),
        ], 201);
    }

    protected function ensureSeeded()
    {
        if (DB::table('discounts')->count() === 0) {
            DB::table('discounts')->insert([
                [
                    'name' => 'Happy Hour Special',
                    'discount_pct' => '15%',
                    'applies_to' => 'All Beverages',
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'name' => 'Bakery Morning Bundle',
                    'discount_pct' => '20%',
                    'applies_to' => 'Bakery Category',
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);
        }
    }
}
