<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class ProductController extends Controller
{
    protected function getTenantId(Request $request): ?string
    {
        return $request->header('X-Tenant-Id')
            ?? $request->user()?->tenant_id
            ?? $request->query('tenant_id')
            ?? null;
    }

    public function bulkStore(Request $request)
    {
        $tenantId = $this->getTenantId($request);
        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.name' => 'required|string|max:255',
            'items.*.sku' => 'required|string|max:100',
            'items.*.barcode' => 'nullable|string|max:100',
            'items.*.selling_price' => 'required|numeric|min:0',
            'items.*.cost_price' => 'nullable|numeric|min:0',
            'items.*.category_id' => 'nullable|string|max:36',
            'items.*.category_name' => 'nullable|string|max:255',
            'items.*.min_reorder_point' => 'nullable|integer|min:0',
            'items.*.description' => 'nullable|string',
            'items.*.initial_stock' => 'nullable|integer|min:0',
        ]);

        $createdCount = 0;
        $errors = [];

        DB::transaction(function () use ($validated, $tenantId, &$createdCount, &$errors) {
            foreach ($validated['items'] as $index => $item) {
                $skuQuery = DB::table('products')->where('sku', $item['sku']);
                if ($tenantId) {
                    $skuQuery->where('tenant_id', $tenantId);
                }
                $existing = $skuQuery->first();
                if ($existing) {
                    $errors[] = "Row #" . ($index + 1) . ": SKU '{$item['sku']}' already exists.";
                    continue;
                }

                $categoryId = $item['category_id'] ?? null;
                if (!$categoryId && !empty($item['category_name'])) {
                    $catQuery = DB::table('categories')->where('name', $item['category_name']);
                    if ($tenantId) {
                        $catQuery->where(function ($q) use ($tenantId) {
                            $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id');
                        });
                    }
                    $cat = $catQuery->first();
                    if ($cat) {
                        $categoryId = $cat->id;
                    } else {
                        $categoryId = DB::table('categories')->insertGetId([
                            'tenant_id' => $tenantId,
                            'name' => $item['category_name'],
                            'slug' => Str::slug($item['category_name']) . '-' . rand(100, 999),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }

                $productId = DB::table('products')->insertGetId([
                    'tenant_id' => $tenantId,
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
                    'tenant_id' => $tenantId,
                    'outlet_id' => 1,
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

        return response()->json([
            'status' => 'success',
            'message' => "Successfully imported {$createdCount} products.",
            'created_count' => $createdCount,
            'errors' => $errors,
        ]);
    }

    public function index(Request $request)
    {
        $tenantId = $this->getTenantId($request);
        $outletId = $request->query('outlet_id', 1);
        $categoryId = $request->query('category_id');
        $search = $request->query('q');
        $stockStatus = $request->query('stock_status');
        $sortBy = $request->query('sort_by', 'created_at');
        $sortOrder = strtolower($request->query('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';

        $query = DB::table('products as p')
            ->leftJoin('categories as c', 'p.category_id', '=', 'c.id')
            ->leftJoin('brands as b', 'p.brand_id', '=', 'b.id')
            ->leftJoin('inventory_balances as ib', function ($join) use ($outletId) {
                $join->on('p.id', '=', 'ib.product_id')
                    ->where('ib.outlet_id', '=', $outletId);
            })
            ->where('p.is_active', true);

        // Scope to tenant organization
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
        }

        if (!empty($categoryId)) {
            $query->where('p.category_id', $categoryId);
        }

        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('p.name', 'like', "%{$search}%")
                  ->orWhere('p.sku', 'like', "%{$search}%")
                  ->orWhere('p.barcode', 'like', "%{$search}%");
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

        $products = $query->select(
                'p.id',
                'p.tenant_id',
                'p.name',
                'p.sku',
                'p.barcode',
                'p.description',
                'p.selling_price',
                'p.selling_price as price',
                'p.cost_price',
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
            )
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $products,
        ]);
    }

    public function show($id)
    {
        $product = DB::table('products as p')
            ->leftJoin('categories as c', 'p.category_id', '=', 'c.id')
            ->where('p.id', $id)
            ->select('p.*', 'c.name as category_name')
            ->first();

        if (!$product) {
            return response()->json(['status' => 'error', 'message' => 'Product not found'], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $product,
        ]);
    }

    public function showByBarcode($code)
    {
        $product = DB::table('barcodes as b')
            ->join('products as p', 'b.product_id', '=', 'p.id')
            ->leftJoin('categories as c', 'p.category_id', '=', 'c.id')
            ->where('b.barcode', $code)
            ->where('p.is_active', true)
            ->select('p.id', 'p.name', 'p.sku', 'p.selling_price as price', 'c.name as category', 'b.barcode')
            ->first();

        if (!$product) {
            // Fallback: Check if code matches product SKU
            $product = DB::table('products as p')
                ->leftJoin('categories as c', 'p.category_id', '=', 'c.id')
                ->where('p.sku', $code)
                ->where('p.is_active', true)
                ->select('p.id', 'p.name', 'p.sku', 'p.selling_price as price', 'c.name as category')
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

    public function store(Request $request)
    {
        $tenantId = $this->getTenantId($request);
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'sku' => 'required|string|max:100',
            'barcode' => 'nullable|string|max:100',
            'selling_price' => 'required|numeric|min:0',
            'cost_price' => 'nullable|numeric|min:0',
            'category_id' => 'nullable|string|max:36',
            'brand_id' => 'nullable|string|max:36',
            'expiry_date' => 'nullable|date',
            'min_reorder_point' => 'nullable|integer|min:0',
            'description' => 'nullable|string',
            'initial_stock' => 'nullable|numeric|min:0',
        ]);

        // Check if SKU exists for this tenant
        $skuCheck = DB::table('products')->where('sku', $validated['sku']);
        if ($tenantId) {
            $skuCheck->where('tenant_id', $tenantId);
        }
        if ($skuCheck->exists()) {
            return response()->json([
                'status' => 'error',
                'message' => "SKU '{$validated['sku']}' is already in use for your organization.",
            ], 422);
        }

        $outlet = DB::table('outlets')->first();
        $outletId = $outlet ? $outlet->id : 1;

        $productId = DB::table('products')->insertGetId([
            'tenant_id' => $tenantId,
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
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $initialStock = isset($validated['initial_stock']) ? (float)$validated['initial_stock'] : 0;
        DB::table('inventory_balances')->insert([
            'tenant_id' => $tenantId,
            'outlet_id' => $outletId,
            'product_id' => $productId,
            'on_hand' => $initialStock,
            'reserved' => 0,
            'available' => $initialStock,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Product created successfully.',
            'data' => [
                'id' => $productId,
                'tenant_id' => $tenantId,
                'name' => $validated['name'],
                'sku' => $validated['sku'],
            ],
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $tenantId = $this->getTenantId($request);
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'sku' => 'sometimes|string|max:100',
            'barcode' => 'sometimes|nullable|string|max:100',
            'selling_price' => 'sometimes|numeric|min:0',
            'cost_price' => 'sometimes|numeric|min:0',
            'category_id' => 'sometimes|nullable|string|max:36',
            'brand_id' => 'sometimes|nullable|string|max:36',
            'expiry_date' => 'sometimes|nullable|date',
            'min_reorder_point' => 'sometimes|nullable|integer|min:0',
            'description' => 'sometimes|nullable|string',
        ]);

        $query = DB::table('products')->where('id', $id);
        if ($tenantId) {
            $query->where(function ($q) use ($tenantId) {
                $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id');
            });
        }

        $validated['updated_at'] = now();
        $query->update($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Product updated successfully.',
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $tenantId = $this->getTenantId($request);
        $query = DB::table('products')->where('id', $id);
        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }

        $query->update(['is_active' => false, 'updated_at' => now()]);

        return response()->json([
            'status' => 'success',
            'message' => 'Product deleted successfully.',
        ]);
    }
}
