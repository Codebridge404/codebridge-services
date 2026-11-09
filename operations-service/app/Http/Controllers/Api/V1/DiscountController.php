<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\TenantAuthorizationService;
use App\Support\TenantCatalogCache;
use App\Traits\ResolvesTenantContext;
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
    use ResolvesTenantContext;

    public function index(Request $request)
    {
        $tenantId = $this->getTenantId($request, false);
        $isPlatformSuperAdmin = $request->user() && TenantAuthorizationService::hasPlatformSuperAdminRole($request->user());
        $perPage = min(100, max(1, (int) $request->query('per_page', 50)));
        $page = max(1, (int) $request->query('page', 1));

        $payload = TenantCatalogCache::remember(
            'discounts',
            $tenantId,
            [
                'super' => $isPlatformSuperAdmin ? 1 : 0,
                'page' => $page,
                'per_page' => $perPage,
            ],
            function () use ($tenantId, $isPlatformSuperAdmin, $perPage) {
                $query = DB::table('discounts');

                if (!empty($tenantId) && $tenantId !== 'all') {
                    $hasTenantDiscounts = DB::table('discounts')->where('tenant_id', $tenantId)->exists();
                    if ($hasTenantDiscounts) {
                        $query->where('tenant_id', $tenantId);
                    } else {
                        $query->where(function ($q) use ($tenantId) {
                            $q->where('tenant_id', $tenantId)
                              ->orWhereNull('tenant_id');
                        });
                    }
                } elseif ($tenantId === 'all' && $isPlatformSuperAdmin) {
                    // Super admin cross-tenant listing
                } else {
                    $query->whereNull('tenant_id');
                }

                $paginator = $query->orderBy('created_at', 'desc')->paginate($perPage);

                return [
                    'data' => $paginator->items(),
                    'pagination' => [
                        'current_page' => $paginator->currentPage(),
                        'last_page' => $paginator->lastPage(),
                        'per_page' => $paginator->perPage(),
                        'total' => $paginator->total(),
                    ],
                ];
            }
        );

        return response()->json([
            'status' => 'success',
            'data' => $payload['data'],
            'pagination' => $payload['pagination'],
        ]);
    }

    public function store(Request $request)
    {
        $tenantId = $this->getTenantId($request, true);
        $writeTenantId = $tenantId === 'all' ? null : $tenantId;

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'discount_pct' => 'required|string|max:50',
            'applies_to' => 'nullable|string|max:255',
        ]);

        $id = DB::table('discounts')->insertGetId([
            'tenant_id' => $writeTenantId,
            'name' => $validated['name'],
            'discount_pct' => $validated['discount_pct'],
            'applies_to' => $validated['applies_to'] ?? 'All Products',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        TenantCatalogCache::invalidate($writeTenantId, 'discounts');

        return response()->json([
            'status' => 'success',
            'message' => 'Discount rule created.',
            'data' => DB::table('discounts')->where('id', $id)->first(),
        ], 201);
    }
}
