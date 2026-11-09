<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Product\BulkStoreProductRequest;
use App\Http\Requests\Product\StoreProductRequest;
use App\Http\Requests\Product\UpdateProductRequest;
use App\Http\Requests\Product\UploadProductImageRequest;
use App\Services\TenantAuthorizationService;
use App\Support\TenantCatalogCache;
use App\Traits\ResolvesTenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

/**
 * [BD-0010] Enterprise Product Catalog, Pricing & Barcode SKU Controller.
 *
 * @ticket BD-0010
 * @component operations-service
 * @file codebridge-services/operations-service/app/Http/Controllers/Api/V1/ProductController.php
 * @related-model App\Models\Product, App\Models\Category, App\Models\Brand
 * @entry-point GET /api/v1/products, POST /api/v1/products, POST /api/v1/products/bulk
 * @date-time 2026-10-23T15:40:00+07:00
 *
 * Process Capabilities:
 * 1. SKU generation, EAN-13 barcode lookups, and category tagging.
 * 2. Multi-tier retail pricing and cost accounting.
 * 3. Atomic bulk product ingestion.
 * 4. Multi-tenant catalog scoping via ResolvesTenantContext.
 */
class ProductController extends Controller
{
    use ResolvesTenantContext;

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    private function resolveWriteTenantId(?string $tenantId): ?string
    {
        return ($tenantId === null || $tenantId === 'all') ? null : $tenantId;
    }

    private function isValidRelatedResourceTenant(string $table, $id, ?string $tenantId): bool
    {
        $row = DB::table($table)->where('id', $id)->first();
        if (!$row) {
            return false;
        }
        if ($tenantId === null || $row->tenant_id === null) {
            return $row->tenant_id === null || $tenantId === null;
        }

        return (string) $row->tenant_id === (string) $tenantId;
    }

    private function hasForbiddenDataUri($url): bool
    {
        if (!is_string($url) || trim($url) === '') {
            return false;
        }
        $trimmed = trim($url);
        if (preg_match('/^(?:javascript|vbscript):/i', $trimmed)) {
            return true;
        }
        if (stripos($trimmed, 'data:') === 0) {
            return !preg_match('/^data:image\/(jpeg|jpg|png|gif|webp);base64,/i', $trimmed);
        }

        $path = parse_url($trimmed, PHP_URL_PATH) ?: $trimmed;
        return (bool) preg_match('/\.svgz?(?:$|[?#])/i', $path);
    }

    public function bulkStore(BulkStoreProductRequest $request)
    {
        $tenantId = $this->getTenantId($request, true);
        $writeTenantId = $this->resolveWriteTenantId($tenantId);
        $validated = $request->validated();

        $createdCount = 0;
        $errors = [];

        $defaultOutletId = DB::table('outlets')
            ->when($writeTenantId !== null, fn ($q) => $q->where('tenant_id', $writeTenantId))
            ->value('id') ?? 1;

        DB::transaction(function () use ($validated, $writeTenantId, $defaultOutletId, &$createdCount, &$errors) {
            foreach ($validated['items'] as $index => $item) {
                $existing = DB::table('products')
                    ->where('sku', $item['sku'])
                    ->when(
                        $writeTenantId !== null,
                        fn ($q) => $q->where('tenant_id', $writeTenantId),
                        fn ($q) => $q->whereNull('tenant_id')
                    )
                    ->first();
                if ($existing) {
                    $errors[] = "Row #" . ($index + 1) . ": SKU '{$item['sku']}' already exists.";
                    continue;
                }

                $categoryId = $item['category_id'] ?? null;
                if ($categoryId !== null && !$this->isValidRelatedResourceTenant('categories', $categoryId, $writeTenantId)) {
                    $errors[] = "Row #" . ($index + 1) . ": Category ID '{$categoryId}' does not belong to your organization.";
                    continue;
                }

                if (!$categoryId && !empty($item['category_name'])) {
                    $cat = DB::table('categories')
                        ->where('name', $item['category_name'])
                        ->where(function ($q) use ($writeTenantId) {
                            if ($writeTenantId !== null) {
                                $q->where('tenant_id', $writeTenantId)->orWhereNull('tenant_id');
                            } else {
                                $q->whereNull('tenant_id');
                            }
                        })
                        ->first();

                    if ($cat) {
                        $categoryId = $cat->id;
                    } else {
                        $categoryId = DB::table('categories')->insertGetId([
                            'tenant_id' => $writeTenantId,
                            'name' => $item['category_name'],
                            'slug' => Str::slug($item['category_name']) . '-' . rand(100, 999),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }

                $productId = DB::table('products')->insertGetId([
                    'tenant_id' => $writeTenantId,
                    'category_id' => $categoryId,
                    'name' => $item['name'],
                    'sku' => $item['sku'],
                    'barcode' => $item['barcode'] ?? null,
                    'description' => $item['description'] ?? null,
                    'cost_price' => $item['cost_price'] ?? 0.00,
                    'selling_price' => $item['selling_price'],
                    'min_reorder_point' => isset($item['min_reorder_point']) ? (int)$item['min_reorder_point'] : 0,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $initialStock = $item['initial_stock'] ?? 0;
                DB::table('inventory_balances')->insert([
                    'tenant_id' => $writeTenantId,
                    'outlet_id' => $defaultOutletId,
                    'product_id' => $productId,
                    'on_hand' => $initialStock,
                    'reserved' => 0,
                    'available' => $initialStock,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $createdCount++;
            }
        });

        TenantCatalogCache::invalidate($writeTenantId, ['products', 'categories']);

        return response()->json([
            'status' => 'success',
            'message' => "Successfully imported {$createdCount} products.",
            'created_count' => $createdCount,
            'errors' => $errors,
        ]);
    }

    public function index(Request $request)
    {
        $tenantId = $this->getTenantId($request, false);
        $outletId = max(1, (int) $request->query('outlet_id', 1));
        $categoryId = $request->query('category_id');
        // Support both ?q= and ?search=
        $search = $request->query('q') ?? $request->query('search');
        $stockStatus = $request->query('stock_status');
        $sortBy = $request->query('sort_by', 'created_at');
        $sortOrder = strtolower((string) $request->query('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';
        $perPage = min(100, max(1, (int) $request->query('per_page', 50)));
        $page = max(1, (int) $request->query('page', 1));
        $isStaff = $request->user() !== null;
        $isPlatformSuperAdmin = $isStaff && TenantAuthorizationService::hasPlatformSuperAdminRole($request->user());

        $cacheParams = [
            'staff' => $isStaff ? 1 : 0,
            'super' => $isPlatformSuperAdmin ? 1 : 0,
            'outlet_id' => $outletId,
            'category_id' => $categoryId !== null ? (string) $categoryId : '',
            'search' => trim((string) ($search ?? '')),
            'stock_status' => (string) ($stockStatus ?? ''),
            'sort_by' => (string) $sortBy,
            'sort_order' => $sortOrder,
            'page' => $page,
            'per_page' => $perPage,
        ];

        $payload = TenantCatalogCache::remember(
            'products',
            $tenantId,
            $cacheParams,
            function () use ($tenantId, $outletId, $categoryId, $search, $stockStatus, $sortBy, $sortOrder, $perPage, $isStaff, $isPlatformSuperAdmin) {
                $query = DB::table('products as p')
                    ->leftJoin('categories as c', 'p.category_id', '=', 'c.id')
                    ->leftJoin('brands as b', 'p.brand_id', '=', 'b.id')
                    ->leftJoin('inventory_balances as ib', function ($join) use ($outletId, $tenantId) {
                        $join->on('p.id', '=', 'ib.product_id')
                            ->where('ib.outlet_id', '=', $outletId);
                        if (!empty($tenantId) && $tenantId !== 'all') {
                            $join->where(function ($j) use ($tenantId) {
                                $j->where('ib.tenant_id', '=', $tenantId)
                                  ->orWhereNull('ib.tenant_id');
                            });
                        }
                    })
                    ->where('p.is_active', true);

                // Scope to tenant organization strictly
                if (!empty($tenantId) && $tenantId !== 'all') {
                    $hasTenantProducts = DB::table('products')
                        ->where('tenant_id', $tenantId)
                        ->where('is_active', true)
                        ->exists();

                    if ($hasTenantProducts) {
                        $query->where('p.tenant_id', $tenantId);
                    } else {
                        // If tenant hasn't added products yet, allow shared defaults so catalog is not blank
                        $query->where(function ($q) use ($tenantId) {
                            $q->where('p.tenant_id', $tenantId)
                              ->orWhereNull('p.tenant_id');
                        });
                    }
                } elseif ($tenantId === 'all' && $isPlatformSuperAdmin) {
                    // Explicitly permitted platform super_admin cross-tenant view
                } else {
                    // Unauthenticated or unscoped visitor only sees global shared catalog entries
                    $query->whereNull('p.tenant_id');
                }

                if (!empty($categoryId)) {
                    $query->where('p.category_id', $categoryId);
                }

                if (!empty($search)) {
                    $searchTerm = $this->escapeLike(trim((string) $search));
                    $query->where(function ($q) use ($searchTerm) {
                        $q->where('p.name', 'like', "%{$searchTerm}%")
                          ->orWhere('p.sku', 'like', "%{$searchTerm}%")
                          ->orWhere('p.barcode', 'like', "%{$searchTerm}%");
                    });
                }

                if ($stockStatus === 'in_stock') {
                    $query->where(DB::raw('COALESCE(ib.on_hand, 0)'), '>', 0);
                } elseif ($stockStatus === 'low_stock') {
                    $query->whereRaw('COALESCE(p.min_reorder_point, 0) > 0')
                          ->whereRaw('COALESCE(ib.on_hand, 0) <= p.min_reorder_point')
                          ->whereRaw('COALESCE(ib.on_hand, 0) > 0');
                } elseif ($stockStatus === 'out_of_stock') {
                    $query->where(DB::raw('COALESCE(ib.on_hand, 0)'), '<=', 0);
                }

                // Sorting
                if ($sortBy === 'name') {
                    $query->orderBy('p.name', $sortOrder);
                } elseif ($sortBy === 'price') {
                    $query->orderBy('p.selling_price', $sortOrder);
                } elseif ($sortBy === 'stock') {
                    $query->orderBy(DB::raw('COALESCE(ib.on_hand, 0)'), $sortOrder);
                } else {
                    $query->orderBy('p.created_at', $sortOrder)->orderBy('p.id', $sortOrder);
                }

                $selectColumns = [
                    'p.id',
                    'p.tenant_id',
                    'p.name',
                    'p.sku',
                    'p.barcode',
                    'p.description',
                    'p.selling_price',
                    'p.selling_price as price',
                    'p.image_url',
                    'p.min_reorder_point',
                    'p.category_id',
                    'c.name as category',
                    'c.name as category_name',
                    'p.brand_id',
                    'b.name as brand_name',
                    'p.expiry_date',
                    'p.is_active',
                    DB::raw('COALESCE(ib.on_hand, 0) as stock_on_hand')
                ];

                // Cost price is an internal accounting metric: only reveal to authenticated staff
                if ($isStaff) {
                    $selectColumns[] = 'p.cost_price';
                }

                $paginator = $query->select($selectColumns)->paginate($perPage);

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

    public function show(Request $request, $id)
    {
        $product = DB::table('products as p')
            ->leftJoin('categories as c', 'p.category_id', '=', 'c.id')
            ->where('p.id', $id)
            ->select('p.*', 'c.name as category_name')
            ->first();

        if (!$product) {
            return response()->json(['status' => 'error', 'message' => 'Product not found'], 404);
        }

        $tenantId = $this->getTenantId($request, false);
        $isPlatformSuperAdmin = $request->user() && TenantAuthorizationService::hasPlatformSuperAdminRole($request->user());
        if ($product->tenant_id !== null && !$isPlatformSuperAdmin) {
            if (empty($tenantId) || ($tenantId !== 'all' && (string) $product->tenant_id !== (string) $tenantId)) {
                return response()->json(['status' => 'error', 'message' => 'Product not found'], 404);
            }
        }

        // Prevent leaking internal costs to unauthenticated visitors
        if (!$request->user()) {
            unset($product->cost_price);
        }

        return response()->json([
            'status' => 'success',
            'data' => $product,
        ]);
    }

    public function showByBarcode(Request $request, $code)
    {
        $tenantId = $this->getTenantId($request, false);
        $isPlatformSuperAdmin = $request->user() && TenantAuthorizationService::hasPlatformSuperAdminRole($request->user());

        $barcodeQuery = DB::table('barcodes as b')
            ->join('products as p', 'b.product_id', '=', 'p.id')
            ->leftJoin('categories as c', 'p.category_id', '=', 'c.id')
            ->where('b.barcode', $code)
            ->where('p.is_active', true);

        if (!empty($tenantId) && $tenantId !== 'all') {
            $barcodeQuery->where(function ($q) use ($tenantId) {
                $q->where('p.tenant_id', $tenantId)->orWhereNull('p.tenant_id');
            });
        } elseif ($tenantId === 'all' && $isPlatformSuperAdmin) {
            // Super admin cross-tenant lookup
        } else {
            $barcodeQuery->whereNull('p.tenant_id');
        }

        $product = $barcodeQuery->select('p.id', 'p.tenant_id', 'p.name', 'p.sku', 'p.selling_price as price', 'c.name as category', 'b.barcode', 'p.image_url')
            ->first();

        if (!$product) {
            // Fallback: Check if code matches product SKU
            $skuQuery = DB::table('products as p')
                ->leftJoin('categories as c', 'p.category_id', '=', 'c.id')
                ->where('p.sku', $code)
                ->where('p.is_active', true);

            if (!empty($tenantId) && $tenantId !== 'all') {
                $skuQuery->where(function ($q) use ($tenantId) {
                    $q->where('p.tenant_id', $tenantId)->orWhereNull('p.tenant_id');
                });
            } elseif ($tenantId === 'all' && $isPlatformSuperAdmin) {
                // Super admin cross-tenant lookup
            } else {
                $skuQuery->whereNull('p.tenant_id');
            }

            $product = $skuQuery->select('p.id', 'p.tenant_id', 'p.name', 'p.sku', 'p.selling_price as price', 'c.name as category', 'p.image_url')
                ->first();
        }

        if (!$product) {
            return response()->json(['status' => 'error', 'message' => 'No product found matching barcode ' . $code], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $product,
        ]);
    }

    public function store(StoreProductRequest $request)
    {
        $tenantId = $this->getTenantId($request, true);
        $writeTenantId = $this->resolveWriteTenantId($tenantId);
        $validated = $request->validated();

        if (!empty($validated['image_url']) && $this->hasForbiddenDataUri($validated['image_url'])) {
            return response()->json(['status' => 'error', 'message' => 'Unsupported or unsafe image data URI format.'], 422);
        }

        if (!empty($validated['category_id']) && !$this->isValidRelatedResourceTenant('categories', $validated['category_id'], $writeTenantId)) {
            return response()->json(['status' => 'error', 'message' => 'Selected category does not belong to your organization.'], 422);
        }

        if (!empty($validated['brand_id']) && !$this->isValidRelatedResourceTenant('brands', $validated['brand_id'], $writeTenantId)) {
            return response()->json(['status' => 'error', 'message' => 'Selected brand does not belong to your organization.'], 422);
        }

        // Check if SKU exists for this specific tenant
        $skuCheck = DB::table('products')
            ->where('sku', $validated['sku'])
            ->when(
                $writeTenantId !== null,
                fn ($q) => $q->where('tenant_id', $writeTenantId),
                fn ($q) => $q->whereNull('tenant_id')
            );

        if ($skuCheck->exists()) {
            return response()->json([
                'status' => 'error',
                'message' => "SKU '{$validated['sku']}' is already in use for your organization.",
            ], 422);
        }

        // Upload product image to MinIO S3 bucket (or Cloudinary fallback)
        $imageUrl = $validated['image_url'] ?? null;
        try {
            if ($request->hasFile('image')) {
                $cloudinary = new \App\Services\CloudinaryService();
                $uploadedUrl = $cloudinary->upload($request->file('image'), 'products');
                if ($uploadedUrl) {
                    $imageUrl = $uploadedUrl;
                }
            } elseif (!empty($imageUrl) && stripos($imageUrl, 'data:image/') === 0) {
                $cloudinary = new \App\Services\CloudinaryService();
                $uploadedUrl = $cloudinary->upload($imageUrl, 'products');
                if ($uploadedUrl) {
                    $imageUrl = $uploadedUrl;
                }
            }
        } catch (\InvalidArgumentException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }

        $outlet = DB::table('outlets')
            ->when($writeTenantId !== null, fn ($q) => $q->where('tenant_id', $writeTenantId))
            ->first();
        $outletId = $outlet ? $outlet->id : 1;

        $productId = DB::table('products')->insertGetId([
            'tenant_id' => $writeTenantId,
            'name' => $validated['name'],
            'sku' => $validated['sku'],
            'barcode' => $validated['barcode'] ?? null,
            'selling_price' => $validated['selling_price'],
            'cost_price' => $validated['cost_price'] ?? 0.00,
            'category_id' => $validated['category_id'] ?? null,
            'brand_id' => $validated['brand_id'] ?? null,
            'expiry_date' => $validated['expiry_date'] ?? null,
            'min_reorder_point' => isset($validated['min_reorder_point']) ? (int)$validated['min_reorder_point'] : 0,
            'description' => $validated['description'] ?? null,
            'image_url' => $imageUrl,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $initialStock = isset($validated['initial_stock']) ? (float)$validated['initial_stock'] : 0;
        DB::table('inventory_balances')->insert([
            'tenant_id' => $writeTenantId,
            'outlet_id' => $outletId,
            'product_id' => $productId,
            'on_hand' => $initialStock,
            'reserved' => 0,
            'available' => $initialStock,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        TenantCatalogCache::invalidate($writeTenantId, 'products');

        return response()->json([
            'status' => 'success',
            'message' => 'Product created successfully.',
            'data' => [
                'id' => $productId,
                'tenant_id' => $writeTenantId,
                'name' => $validated['name'],
                'sku' => $validated['sku'],
                'image_url' => $imageUrl,
            ],
        ], 201);
    }

    public function update(UpdateProductRequest $request, $id)
    {
        $this->getTenantId($request, true);

        $product = DB::table('products')->where('id', $id)->first();
        if (!$product) {
            return response()->json(['status' => 'error', 'message' => 'Product not found'], 404);
        }

        $this->authorizeTenantResource($product->tenant_id, $request, 'Product');

        $validated = $request->validated();

        if (!empty($validated['image_url']) && $this->hasForbiddenDataUri($validated['image_url'])) {
            return response()->json(['status' => 'error', 'message' => 'Unsupported or unsafe image data URI format.'], 422);
        }

        if (!empty($validated['category_id']) && !$this->isValidRelatedResourceTenant('categories', $validated['category_id'], $product->tenant_id !== null ? (string) $product->tenant_id : null)) {
            return response()->json(['status' => 'error', 'message' => 'Selected category does not belong to your organization.'], 422);
        }

        if (!empty($validated['brand_id']) && !$this->isValidRelatedResourceTenant('brands', $validated['brand_id'], $product->tenant_id !== null ? (string) $product->tenant_id : null)) {
            return response()->json(['status' => 'error', 'message' => 'Selected brand does not belong to your organization.'], 422);
        }

        try {
            if ($request->hasFile('image')) {
                $cloudinary = new \App\Services\CloudinaryService();
                $uploadedUrl = $cloudinary->upload($request->file('image'), 'products');
                if ($uploadedUrl) {
                    $validated['image_url'] = $uploadedUrl;
                }
            } elseif (isset($validated['image_url']) && stripos($validated['image_url'], 'data:image/') === 0) {
                $cloudinary = new \App\Services\CloudinaryService();
                $uploadedUrl = $cloudinary->upload($validated['image_url'], 'products');
                if ($uploadedUrl) {
                    $validated['image_url'] = $uploadedUrl;
                }
            }
        } catch (\InvalidArgumentException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }
        unset($validated['image']);

        $validated['updated_at'] = now();
        DB::table('products')
            ->where('id', $id)
            ->when(
                $product->tenant_id !== null,
                fn ($q) => $q->where('tenant_id', $product->tenant_id),
                fn ($q) => $q->whereNull('tenant_id')
            )
            ->update($validated);

        TenantCatalogCache::invalidate($product->tenant_id !== null ? (string) $product->tenant_id : null, 'products');

        return response()->json([
            'status' => 'success',
            'message' => 'Product updated successfully.',
            'image_url' => $validated['image_url'] ?? null,
        ]);
    }

    public function uploadImage(UploadProductImageRequest $request, $id)
    {
        $this->getTenantId($request, true);
        $validated = $request->validated();

        if (!empty($validated['image_url']) && $this->hasForbiddenDataUri($validated['image_url'])) {
            return response()->json(['status' => 'error', 'message' => 'Unsupported or unsafe image data URI format.'], 422);
        }

        $product = DB::table('products')->where('id', $id)->first();
        if (!$product) {
            return response()->json(['status' => 'error', 'message' => 'Product not found'], 404);
        }

        $this->authorizeTenantResource($product->tenant_id, $request, 'Product');

        $imageUrl = null;
        $cloudinary = new \App\Services\CloudinaryService();
        try {
            if ($request->hasFile('image')) {
                $imageUrl = $cloudinary->upload($request->file('image'), 'products');
            } elseif ($request->filled('image_url')) {
                $imageUrl = $cloudinary->upload($request->input('image_url'), 'products');
            }
        } catch (\InvalidArgumentException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }

        if (!$imageUrl) {
            return response()->json(['status' => 'error', 'message' => 'Image upload failed'], 500);
        }

        DB::table('products')
            ->where('id', $id)
            ->when(
                $product->tenant_id !== null,
                fn ($q) => $q->where('tenant_id', $product->tenant_id),
                fn ($q) => $q->whereNull('tenant_id')
            )
            ->update([
                'image_url' => $imageUrl,
                'updated_at' => now(),
            ]);

        TenantCatalogCache::invalidate($product->tenant_id !== null ? (string) $product->tenant_id : null, 'products');

        return response()->json([
            'status' => 'success',
            'message' => 'Product image uploaded successfully.',
            'data' => [
                'id' => $id,
                'image_url' => $imageUrl,
            ],
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $this->getTenantId($request, true);

        $product = DB::table('products')->where('id', $id)->first();
        if (!$product) {
            return response()->json(['status' => 'error', 'message' => 'Product not found'], 404);
        }

        $this->authorizeTenantResource($product->tenant_id, $request, 'Product');

        DB::table('products')
            ->where('id', $id)
            ->when(
                $product->tenant_id !== null,
                fn ($q) => $q->where('tenant_id', $product->tenant_id),
                fn ($q) => $q->whereNull('tenant_id')
            )
            ->update(['is_active' => false, 'updated_at' => now()]);

        TenantCatalogCache::invalidate($product->tenant_id !== null ? (string) $product->tenant_id : null, 'products');

        return response()->json([
            'status' => 'success',
            'message' => 'Product deleted successfully.',
        ]);
    }
}
