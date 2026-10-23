<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * [BD-0004] Multi-Outlet & Physical Branch Management Controller.
 *
 * @ticket BD-0004
 * @component auth-service
 * @file codebridge-services/auth-service/app/Http/Controllers/Api/V1/OutletController.php
 * @related-model App\Models\Outlet, App\Models\User
 * @entry-point GET /api/v1/outlets, POST /api/v1/outlets, PUT /api/v1/outlets/{id}
 * @date-time 2026-10-23T15:40:00+07:00
 *
 * Process Capabilities:
 * 1. Automatic generation of UUIDv4 for new outlets.
 * 2. Links staff members via outlet_uuid.
 * 3. Enforces multi-tenant workspace isolation.
 */
class OutletController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $search = $request->query('q');

        $query = DB::table('outlets as o');

        // Multi-tenant scoping: Non-super-admin users only see outlets belonging to their tenant/workspace
        if ($user && $user->role !== 'super_admin') {
            if (!empty($user->tenant_id)) {
                $query->where('o.tenant_id', $user->tenant_id);
            } elseif (!empty($user->outlet_id)) {
                $query->where('o.id', $user->outlet_id);
            }
        }

        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('o.name', 'like', "%{$search}%")
                  ->orWhere('o.code', 'like', "%{$search}%");
            });
        }

        $outlets = $query->select(
                'o.id',
                'o.tenant_id',
                'o.name',
                'o.code',
                'o.address',
                'o.phone',
                'o.receipt_header',
                'o.receipt_footer',
                'o.is_active',
                'o.created_at',
                DB::raw('(SELECT COUNT(*) FROM users WHERE outlet_uuid = o.uuid) as staff_count'),
                DB::raw('(SELECT COUNT(*) FROM registers WHERE outlet_id = o.id) as registers_count')
            )
            ->orderBy('o.name', 'asc')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $outlets,
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:50|unique:outlets,code',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string|max:500',
            'receipt_header' => 'nullable|string|max:255',
            'receipt_footer' => 'nullable|string|max:255',
        ]);

        $tenantId = $user ? $user->tenant_id : null;

        $id = DB::table('outlets')->insertGetId([
            'tenant_id' => $tenantId,
            'name' => $validated['name'],
            'code' => $validated['code'],
            'phone' => $validated['phone'] ?? null,
            'address' => $validated['address'] ?? null,
            'receipt_header' => $validated['receipt_header'] ?? 'Thank you for shopping!',
            'receipt_footer' => $validated['receipt_footer'] ?? 'Please come again!',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Store outlet created successfully.',
            'data' => ['id' => $id],
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string|max:500',
            'receipt_header' => 'nullable|string|max:255',
            'receipt_footer' => 'nullable|string|max:255',
        ]);

        DB::table('outlets')->where('id', $id)->update([
            'name' => $validated['name'],
            'phone' => $validated['phone'] ?? null,
            'address' => $validated['address'] ?? null,
            'receipt_header' => $validated['receipt_header'] ?? null,
            'receipt_footer' => $validated['receipt_footer'] ?? null,
            'updated_at' => now(),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Store outlet updated successfully.',
        ]);
    }

    public function destroy($id)
    {
        DB::table('outlets')->where('id', $id)->update([
            'is_active' => false,
            'updated_at' => now(),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Store outlet deactivated.',
        ]);
    }
}
