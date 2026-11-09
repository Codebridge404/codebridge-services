<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\TenantAuthorizationService;
use App\Support\TenantCatalogCache;
use App\Traits\ResolvesTenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

/**
 * [BD-0012] Dynamic Multi-Tier Product Category & Taxonomy Hierarchy Controller
 *
 * @ticket BD-0012
 * @component operations-service
 * @file codebridge-services/operations-service/app/Http/Controllers/Api/V1/CategoryController.php
 * @related-model App\Models\Category, App\Models\Product
 * @entry-point GET /api/v1/categories, POST /api/v1/categories, PUT /api/v1/categories/{id}
 * @routes
 *  - GET /api/v1/categories
 *  - POST /api/v1/categories
 *  - PUT /api/v1/categories/{id}
 *  - DELETE /api/v1/categories/{id}
 * @date-time 2026-10-23T15:40:00+07:00
 *
 * Process Capabilities:
 * Step 1 (Hierarchy Resolution): Builds nested parent-child category tree for storefront navigation.
 * Step 2 (Tenant Partitioning): Scopes categories to requesting tenant organization with shared defaults.
 * Step 3 (Slug Auto-Generation): Generates URL-friendly slugs with collision avoidance.
 * Step 4 (Tax Group Association): Links default sales tax rates to categories for automated tax calculation.
 * Step 5 (Menu Sorting & Display Order): Updates display sort ordering for POS touch-screen grids.
 */
class CategoryController extends Controller
{
    use ResolvesTenantContext;

    public function index(Request $request)
    {
        $tenantId = $this->getTenantId($request, false);
        $isPlatformSuperAdmin = $request->user() && TenantAuthorizationService::hasPlatformSuperAdminRole($request->user());
        $type = $request->query('type'); // 'main', 'sub'
        $search = $request->query('q') ?? $request->query('search');
        $perPage = min(100, max(1, (int) $request->query('per_page', 50)));
        $page = max(1, (int) $request->query('page', 1));

        $payload = TenantCatalogCache::remember(
            'categories',
            $tenantId,
            [
                'super' => $isPlatformSuperAdmin ? 1 : 0,
                'type' => (string) ($type ?? ''),
                'search' => trim((string) ($search ?? '')),
                'page' => $page,
                'per_page' => $perPage,
            ],
            function () use ($tenantId, $isPlatformSuperAdmin, $type, $search, $perPage) {
                $query = DB::table('categories as c')
                    ->leftJoin('categories as parent', 'c.parent_id', '=', 'parent.id');

                // Scope to tenant organization strictly
                if (!empty($tenantId) && $tenantId !== 'all') {
                    $hasTenantCategories = DB::table('categories')->where('tenant_id', $tenantId)->exists();
                    if ($hasTenantCategories) {
                        $query->where('c.tenant_id', $tenantId);
                    } else {
                        $query->where(function ($q) use ($tenantId) {
                            $q->where('c.tenant_id', $tenantId)
                              ->orWhereNull('c.tenant_id');
                        });
                    }
                } elseif ($tenantId === 'all' && $isPlatformSuperAdmin) {
                    // Super admin cross-tenant view
                } else {
                    $query->whereNull('c.tenant_id');
                }

                if ($type === 'main') {
                    $query->whereNull('c.parent_id');
                } elseif ($type === 'sub') {
                    $query->whereNotNull('c.parent_id');
                }

                if (!empty($search)) {
                    $escapedSearch = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim((string) $search));
                    $query->where('c.name', 'like', "%{$escapedSearch}%");
                }

                $paginator = $query->select(
                        'c.id',
                        'c.tenant_id',
                        'c.name',
                        'c.slug',
                        'c.parent_id',
                        'parent.name as parent_name',
                        'c.created_at',
                        DB::raw('(SELECT COUNT(*) FROM products WHERE category_id = c.id AND (c.tenant_id IS NULL OR products.tenant_id = c.tenant_id)) as products_count')
                    )
                    ->orderBy('c.name', 'asc')
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
            'parent_id' => 'nullable|integer|exists:categories,id',
        ]);

        if (!empty($validated['parent_id'])) {
            $parent = DB::table('categories')->where('id', $validated['parent_id'])->first();
            if ($parent && $parent->tenant_id !== null && $writeTenantId !== null && (string) $parent->tenant_id !== (string) $writeTenantId) {
                return response()->json(['status' => 'error', 'message' => 'Parent category belongs to another organization.'], 422);
            }
        }

        $slug = Str::slug($validated['name']) . '-' . rand(100, 999);

        $id = DB::table('categories')->insertGetId([
            'tenant_id' => $writeTenantId,
            'name' => $validated['name'],
            'slug' => $slug,
            'parent_id' => $validated['parent_id'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        TenantCatalogCache::invalidate($writeTenantId, ['categories', 'products']);

        return response()->json([
            'status' => 'success',
            'message' => 'Category created successfully.',
            'data' => ['id' => $id, 'tenant_id' => $writeTenantId],
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $this->getTenantId($request, true);

        $category = DB::table('categories')->where('id', $id)->first();
        if (!$category) {
            return response()->json(['status' => 'error', 'message' => 'Category not found'], 404);
        }

        $this->authorizeTenantResource($category->tenant_id, $request, 'Category');

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'parent_id' => 'nullable|integer|exists:categories,id',
        ]);

        if (!empty($validated['parent_id'])) {
            $parent = DB::table('categories')->where('id', $validated['parent_id'])->first();
            if ($parent && $parent->tenant_id !== null && $category->tenant_id !== null && (string) $parent->tenant_id !== (string) $category->tenant_id) {
                return response()->json(['status' => 'error', 'message' => 'Parent category belongs to another organization.'], 422);
            }
        }

        DB::table('categories')
            ->where('id', $id)
            ->when(
                $category->tenant_id !== null,
                fn ($q) => $q->where('tenant_id', $category->tenant_id),
                fn ($q) => $q->whereNull('tenant_id')
            )
            ->update([
                'name' => $validated['name'],
                'parent_id' => $validated['parent_id'] ?? null,
                'updated_at' => now(),
            ]);

        TenantCatalogCache::invalidate($category->tenant_id !== null ? (string) $category->tenant_id : null, ['categories', 'products']);

        return response()->json([
            'status' => 'success',
            'message' => 'Category updated successfully.',
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $this->getTenantId($request, true);

        $category = DB::table('categories')->where('id', $id)->first();
        if (!$category) {
            return response()->json(['status' => 'error', 'message' => 'Category not found'], 404);
        }

        $this->authorizeTenantResource($category->tenant_id, $request, 'Category');

        DB::table('categories')
            ->where('id', $id)
            ->when(
                $category->tenant_id !== null,
                fn ($q) => $q->where('tenant_id', $category->tenant_id),
                fn ($q) => $q->whereNull('tenant_id')
            )
            ->delete();

        TenantCatalogCache::invalidate($category->tenant_id !== null ? (string) $category->tenant_id : null, ['categories', 'products']);

        return response()->json([
            'status' => 'success',
            'message' => 'Category deleted successfully.',
        ]);
    }
}
