<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
