<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CategoryController extends Controller
{
    protected function getTenantId(Request $request): ?string
    {
        return $request->header('X-Tenant-Id')
            ?? $request->user()?->tenant_id
            ?? $request->query('tenant_id')
            ?? null;
    }

    public function index(Request $request)
    {
        $tenantId = $this->getTenantId($request);
        $type = $request->query('type'); // 'main', 'sub'
        $search = $request->query('q');

        $query = DB::table('categories as c')
            ->leftJoin('categories as parent', 'c.parent_id', '=', 'parent.id');

        // Scope to tenant organization
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
        }

        if ($type === 'main') {
            $query->whereNull('c.parent_id');
        } elseif ($type === 'sub') {
            $query->whereNotNull('c.parent_id');
        }

        if (!empty($search)) {
            $query->where('c.name', 'like', "%{$search}%");
        }

        $categories = $query->select(
                'c.id',
                'c.tenant_id',
                'c.name',
                'c.slug',
                'c.parent_id',
                'parent.name as parent_name',
                'c.created_at',
                DB::raw('(SELECT COUNT(*) FROM products WHERE category_id = c.id) as products_count')
            )
            ->orderBy('c.name', 'asc')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $categories,
        ]);
    }

    public function store(Request $request)
    {
        $tenantId = $this->getTenantId($request);
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'parent_id' => 'nullable|integer|exists:categories,id',
        ]);

        $slug = Str::slug($validated['name']) . '-' . rand(100, 999);

        $id = DB::table('categories')->insertGetId([
            'tenant_id' => $tenantId,
            'name' => $validated['name'],
            'slug' => $slug,
            'parent_id' => $validated['parent_id'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Category created successfully.',
            'data' => ['id' => $id],
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'parent_id' => 'nullable|integer|exists:categories,id',
        ]);

        DB::table('categories')->where('id', $id)->update([
            'name' => $validated['name'],
            'parent_id' => $validated['parent_id'] ?? null,
            'updated_at' => now(),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Category updated successfully.',
        ]);
    }

    public function destroy($id)
    {
        DB::table('categories')->where('id', $id)->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Category deleted successfully.',
        ]);
    }
}
