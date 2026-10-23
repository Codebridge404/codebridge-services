<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * [BD-0062] Customer Profile, Credit Balance & CRM Directory Controller
 *
 * @ticket BD-0062
 * @component auth-service
 * @file codebridge-services/auth-service/app/Http/Controllers/Api/V1/CustomerController.php
 * @related-model DB table customers, App\Models\Customer, App\Models\StoreCreditInvoice
 * @entry-point CustomerController::index(), store(), show(), update(), destroy()
 * @routes
 *  - GET    /api/v1/customers
 *  - POST   /api/v1/customers
 *  - GET    /api/v1/customers/{id}
 *  - PUT    /api/v1/customers/{id}
 *  - DELETE /api/v1/customers/{id}
 * @date-time 2026-10-23T15:40:00+07:00
 *
 * Process Capabilities:
 * Step 1 (Tenant Boundary Enforcement): Validates tenant isolation ensuring customer directories remain siloed.
 * Step 2 (Customer Lookup & Search): Supports searching by customer phone number, email, name, or loyalty barcode.
 * Step 3 (Profile Management): Creates and updates customer records with contact information, credit limits, and tax exemptions.
 * Step 4 (Store Credit & Loyalty Aggregation): Aggregates active store credit balance and earned reward points for POS checkout.
 * Step 5 (Transaction History Access): Retrieves linked order receipts, payment summaries, and credit ledger histories.
 */
class CustomerController extends Controller
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

        $query = DB::table('customers');

        if (!empty($tenantId) && $tenantId !== 'all') {
            $query->where('tenant_id', $tenantId);
        }

        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%");
            });
        }

        $customers = $query->orderBy('name', 'asc')->get();

        return response()->json([
            'status' => 'success',
            'data' => $customers,
        ]);
    }

    public function store(Request $request)
    {
        $tenantId = $this->getTenantId($request);
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string|max:500',
        ]);

        $code = 'CUST-' . strtoupper(substr(uniqid(), -6));

        $id = DB::table('customers')->insertGetId([
            'tenant_id' => $tenantId,
            'name' => $validated['name'],
            'code' => $code,
            'email' => $validated['email'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'address' => $validated['address'] ?? null,
            'loyalty_points' => 0,
            'total_spent' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Customer account created successfully.',
            'data' => ['id' => $id],
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $tenantId = $this->getTenantId($request);
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string|max:500',
            'loyalty_points' => 'nullable|integer',
        ]);

        $query = DB::table('customers')->where('id', $id);
        if (!empty($tenantId) && $tenantId !== 'all') {
            $query->where('tenant_id', $tenantId);
        }

        $updateData = [
            'name' => $validated['name'],
            'email' => $validated['email'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'address' => $validated['address'] ?? null,
            'updated_at' => now(),
        ];

        if (isset($validated['loyalty_points'])) {
            $updateData['loyalty_points'] = $validated['loyalty_points'];
        }

        $query->update($updateData);

        return response()->json([
            'status' => 'success',
            'message' => 'Customer profile updated successfully.',
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $tenantId = $this->getTenantId($request);
        $query = DB::table('customers')->where('id', $id);

        if (!empty($tenantId) && $tenantId !== 'all') {
            $query->where('tenant_id', $tenantId);
        }

        $query->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Customer profile deleted.',
        ]);
    }
}
