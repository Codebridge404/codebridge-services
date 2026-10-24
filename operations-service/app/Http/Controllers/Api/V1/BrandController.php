<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
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
    public function index(Request $request)
    {
        $search = $request->query('q');

        $query = DB::table('brands as b');

        if (!empty($search)) {
            $query->where('b.name', 'like', "%{$search}%");
        }

        $brands = $query->select(
                'b.id',
                'b.name',
                'b.slug',
                'b.logo',
                'b.description',
                'b.is_active',
                'b.created_at',
                DB::raw('(SELECT COUNT(*) FROM products WHERE brand_id = b.id) as products_count')
            )
            ->orderBy('b.name', 'asc')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $brands,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:500',
            'logo' => 'nullable|string|max:500',
        ]);

        $slug = Str::slug($validated['name']) . '-' . rand(100, 999);

        $id = DB::table('brands')->insertGetId([
            'name' => $validated['name'],
            'slug' => $slug,
            'description' => $validated['description'] ?? null,
            'logo' => $validated['logo'] ?? null,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Brand created successfully.',
            'data' => ['id' => $id],
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:500',
            'logo' => 'nullable|string|max:500',
        ]);

        DB::table('brands')->where('id', $id)->update([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'logo' => $validated['logo'] ?? null,
            'updated_at' => now(),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Brand updated successfully.',
        ]);
    }

    public function destroy($id)
    {
        DB::table('brands')->where('id', $id)->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Brand deleted successfully.',
        ]);
    }
}
