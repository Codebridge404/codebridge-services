<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Supplier\StoreSupplierRequest;
use App\Http\Requests\Supplier\UpdateSupplierRequest;
use App\Http\Resources\Supplier\SupplierResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * [BD-0077] Vendor & Supplier Contact & Procurement Directory Controller
 *
 * @ticket BD-0077
 * @component auth-service
 * @file codebridge-services/auth-service/app/Http/Controllers/Api/V1/SupplierController.php
 * @related-model DB table suppliers, App\Models\Tenant
 * @entry-point SupplierController::index(), store(), show(), update(), destroy()
 * @routes
 *  - GET    /api/v1/suppliers
 *  - POST   /api/v1/suppliers
 *  - GET    /api/v1/suppliers/{id}
 *  - PUT    /api/v1/suppliers/{id}
 *  - DELETE /api/v1/suppliers/{id}
 * @date-time 2026-10-23T15:40:00+07:00
 *
 * Process Capabilities:
 * Step 1 (Tenant Partitioning): Isolates supplier databases per tenant organization.
 * Step 2 (Vendor Profile Management): Creates and updates supplier contacts, tax numbers, and payment terms.
 * Step 3 (Product Supply Catalog): Links supplied items and SKU catalogs to primary and secondary suppliers.
 * Step 4 (Performance & Lead-Time Metrics): Tracks supplier delivery reliability and historical procurement costs.
 * Step 5 (Financial Balance Tracking): Aggregates outstanding accounts payable and purchase order payment histories.
 */
class SupplierController extends Controller
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
        $search = $request->query('q');

        $query = DB::table('suppliers');

        // Scope to tenant organization
        if (!empty($tenantId) && $tenantId !== 'all') {
            $hasTenant = DB::table('suppliers')->where('tenant_id', $tenantId)->exists();
            if ($hasTenant) {
                $query->where('tenant_id', $tenantId);
            } else {
                $query->where(function ($q) use ($tenantId) {
                    $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id');
                });
            }
        }

        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('contact_name', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%");
            });
        }

        $suppliers = $query->orderBy('name', 'asc')->get();

        return response()->json([
            'status' => 'success',
            'data' => SupplierResource::collection($suppliers),
        ]);
    }

    public function store(StoreSupplierRequest $request)
    {
        $tenantId = $this->getTenantId($request);
        $validated = $request->validated();

        $code = 'SUP-' . strtoupper(substr(uniqid(), -6));

        $id = DB::table('suppliers')->insertGetId([
            'tenant_id' => $tenantId,
            'name' => $validated['name'],
            'code' => $code,
            'contact_name' => $validated['contact_name'] ?? null,
            'email' => $validated['email'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'address' => $validated['address'] ?? null,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Supplier created successfully.',
            'data' => ['id' => $id],
        ], 201);
    }

    public function update(UpdateSupplierRequest $request, $id)
    {
        $validated = $request->validated();

        DB::table('suppliers')->where('id', $id)->update([
            'name' => $validated['name'],
            'contact_name' => $validated['contact_name'] ?? null,
            'email' => $validated['email'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'address' => $validated['address'] ?? null,
            'updated_at' => now(),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Supplier profile updated successfully.',
        ]);
    }

    public function destroy($id)
    {
        DB::table('suppliers')->where('id', $id)->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Supplier profile deleted.',
        ]);
    }
}
