<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\TenantAuthorizationService;
use App\Support\TenantCatalogCache;
use App\Traits\ResolvesTenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * [BD-0090] Product Brand Catalog & Brand Asset Management Controller
 *
 * @ticket BD-0090
 * @component operations-service
 * @file codebridge-services/operations-service/app/Http/Controllers/Api/V1/BrandController.php
 * @related-model App\Models\Brand, App\Models\Product
 * @entry-point BrandController::index(), store(), show(), update(), destroy()
 * @routes
 *  - GET    /api/v1/brands
 *  - POST   /api/v1/brands
 *  - GET    /api/v1/brands/{id}
 *  - PUT    /api/v1/brands/{id}
 *  - DELETE /api/v1/brands/{id}
 * @date-time 2026-10-23T15:40:00+07:00
 *
 * Process Capabilities:
 * Step 1 (Multi-Tenant Scoping): Enforces tenant_id isolation for brand catalog directory.
 * Step 2 (Brand Search & Pagination): Returns paginated list of registered brands with logo URLs and product counts.
 * Step 3 (Brand Creation & Validation): Validates unique brand name and slug within tenant.
 * Step 4 (Update & Media Linking): Updates brand details and links official brand logo assets.
 * Step 5 (Cascading Safe Deletion): Checks for linked products before permitting brand deletion.
 */
class BrandController extends Controller
{
    use ResolvesTenantContext;

    public function index(Request $request)
    {
        $tenantId = $this->getTenantId($request, false);
        $isPlatformSuperAdmin = $request->user() && TenantAuthorizationService::hasPlatformSuperAdminRole($request->user());
        $search = $request->query('q') ?? $request->query('search');
        $perPage = min(100, max(1, (int) $request->query('per_page', 50)));
        $page = max(1, (int) $request->query('page', 1));

        $payload = TenantCatalogCache::remember(
            'brands',
            $tenantId,
            [
                'super' => $isPlatformSuperAdmin ? 1 : 0,
                'search' => trim((string) ($search ?? '')),
                'page' => $page,
                'per_page' => $perPage,
            ],
            function () use ($tenantId, $isPlatformSuperAdmin, $search, $perPage) {
                $query = DB::table('brands as b');

                if (!empty($tenantId) && $tenantId !== 'all') {
                    $hasTenantBrands = DB::table('brands')->where('tenant_id', $tenantId)->exists();
                    if ($hasTenantBrands) {
                        $query->where('b.tenant_id', $tenantId);
                    } else {
                        $query->where(function ($q) use ($tenantId) {
                            $q->where('b.tenant_id', $tenantId)
                              ->orWhereNull('b.tenant_id');
                        });
                    }
                } elseif ($tenantId === 'all' && $isPlatformSuperAdmin) {
                    // Super admin cross-tenant listing
                } else {
                    $query->whereNull('b.tenant_id');
                }

                if (!empty($search)) {
                    $escapedSearch = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim((string) $search));
                    $query->where('b.name', 'like', "%{$escapedSearch}%");
                }

                $paginator = $query->select(
                        'b.id',
                        'b.tenant_id',
                        'b.name',
                        'b.slug',
                        'b.logo',
                        'b.description',
                        'b.is_active',
                        'b.created_at',
                        DB::raw('(SELECT COUNT(*) FROM products WHERE brand_id = b.id AND (b.tenant_id IS NULL OR products.tenant_id = b.tenant_id)) as products_count')
                    )
                    ->orderBy('b.name', 'asc')
                    ->paginate($perPage);

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
            'description' => 'nullable|string|max:500',
            'logo' => [
                'nullable',
                'string',
                'max:500',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (!is_string($value) || trim($value) === '') {
                        return;
                    }
                    $trimmed = trim($value);
                    if (stripos($trimmed, 'data:') === 0 && !preg_match('/^data:image\/(jpeg|jpg|png|gif|webp);base64,/i', $trimmed)) {
                        $fail('Unsupported or unsafe brand logo data URI format (SVGs are not permitted).');
                    } elseif (preg_match('/\.svgz?(?:$|[?#])/i', parse_url($trimmed, PHP_URL_PATH) ?: $trimmed)) {
                        $fail('SVG brand logo URLs are not permitted.');
                    }
                },
            ],
        ]);

        $slug = Str::slug($validated['name']) . '-' . rand(100, 999);

        $id = DB::table('brands')->insertGetId([
            'tenant_id' => $writeTenantId,
            'name' => $validated['name'],
            'slug' => $slug,
            'description' => $validated['description'] ?? null,
            'logo' => $validated['logo'] ?? null,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        TenantCatalogCache::invalidate($writeTenantId, ['brands', 'products']);

        return response()->json([
            'status' => 'success',
            'message' => 'Brand created successfully.',
            'data' => ['id' => $id, 'tenant_id' => $writeTenantId],
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $this->getTenantId($request, true);

        $brand = DB::table('brands')->where('id', $id)->first();
        if (!$brand) {
            return response()->json(['status' => 'error', 'message' => 'Brand not found'], 404);
        }

        $this->authorizeTenantResource($brand->tenant_id, $request, 'Brand');

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:500',
            'logo' => [
                'nullable',
                'string',
                'max:500',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (!is_string($value) || trim($value) === '') {
                        return;
                    }
                    $trimmed = trim($value);
                    if (stripos($trimmed, 'data:') === 0 && !preg_match('/^data:image\/(jpeg|jpg|png|gif|webp);base64,/i', $trimmed)) {
                        $fail('Unsupported or unsafe brand logo data URI format (SVGs are not permitted).');
                    } elseif (preg_match('/\.svgz?(?:$|[?#])/i', parse_url($trimmed, PHP_URL_PATH) ?: $trimmed)) {
                        $fail('SVG brand logo URLs are not permitted.');
                    }
                },
            ],
        ]);

        DB::table('brands')
            ->where('id', $id)
            ->when(
                $brand->tenant_id !== null,
                fn ($q) => $q->where('tenant_id', $brand->tenant_id),
                fn ($q) => $q->whereNull('tenant_id')
            )
            ->update([
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'logo' => $validated['logo'] ?? null,
                'updated_at' => now(),
            ]);

        TenantCatalogCache::invalidate($brand->tenant_id !== null ? (string) $brand->tenant_id : null, ['brands', 'products']);

        return response()->json([
            'status' => 'success',
            'message' => 'Brand updated successfully.',
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $this->getTenantId($request, true);

        $brand = DB::table('brands')->where('id', $id)->first();
        if (!$brand) {
            return response()->json(['status' => 'error', 'message' => 'Brand not found'], 404);
        }

        $this->authorizeTenantResource($brand->tenant_id, $request, 'Brand');

        $hasProducts = DB::table('products')
            ->where('brand_id', $id)
            ->when(
                $brand->tenant_id !== null,
                fn ($q) => $q->where('tenant_id', $brand->tenant_id),
                fn ($q) => $q->whereNull('tenant_id')
            )
            ->exists();

        if ($hasProducts) {
            return response()->json([
                'status' => 'error',
                'message' => 'Cannot delete brand with active linked products.',
            ], 422);
        }

        DB::table('brands')
            ->where('id', $id)
            ->when(
                $brand->tenant_id !== null,
                fn ($q) => $q->where('tenant_id', $brand->tenant_id),
                fn ($q) => $q->whereNull('tenant_id')
            )
            ->delete();

        TenantCatalogCache::invalidate($brand->tenant_id !== null ? (string) $brand->tenant_id : null, ['brands', 'products']);

        return response()->json([
            'status' => 'success',
            'message' => 'Brand deleted successfully.',
        ]);
    }
}
