<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
