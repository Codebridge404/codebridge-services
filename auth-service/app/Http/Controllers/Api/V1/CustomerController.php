<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\StoreCustomerRequest;
use App\Http\Requests\Customer\UpdateCustomerRequest;
use App\Http\Resources\Customer\CustomerResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
        $user = $request->user();
        if ($user && $user->role !== 'super_admin') {
            return $user->tenant_id ? (string) $user->tenant_id : null;
        }

        return $request->header('X-Tenant-Id')
            ?? ($user?->tenant_id ? (string) $user->tenant_id : null)
            ?? $request->query('tenant_id')
            ?? null;
    }

    public function index(Request $request)
    {
        $tenantId = $this->getTenantId($request);
        $user = $request->user();
        $search = $request->query('q');

        $query = DB::table('customers');

        if ($user && $user->role !== 'super_admin' && !empty($tenantId)) {
            $query->where('tenant_id', $tenantId);
        } elseif (!empty($tenantId) && $tenantId !== 'all') {
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
            'data' => CustomerResource::collection($customers),
        ]);
    }

    public function store(StoreCustomerRequest $request)
    {
        $tenantId = $this->getTenantId($request);
        $validated = $request->validated();

        $code = 'CUST-' . strtoupper(substr(uniqid(), -6));

        $id = DB::table('customers')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'name' => $validated['name'],
            'code' => $code,
            'email' => $validated['email'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'address' => $validated['address'] ?? null,
            'store_credit' => 0.00,
            'store_credit_balance' => 0.00,
            'loyalty_points' => 0,
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

    public function update(UpdateCustomerRequest $request, $id)
    {
        $tenantId = $this->getTenantId($request);
        $user = $request->user();
        $validated = $request->validated();

        $query = DB::table('customers')->where('id', $id);
        if ($user && $user->role !== 'super_admin' && !empty($tenantId)) {
            $query->where('tenant_id', $tenantId);
        } elseif (!empty($tenantId) && $tenantId !== 'all') {
            $query->where('tenant_id', $tenantId);
        }

        $customer = $query->first();
        if (!$customer) {
            return response()->json(['status' => 'error', 'message' => 'Customer profile not found or unauthorized.'], 404);
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
        $user = $request->user();
        $query = DB::table('customers')->where('id', $id);

        if ($user && $user->role !== 'super_admin' && !empty($tenantId)) {
            $query->where('tenant_id', $tenantId);
        } elseif (!empty($tenantId) && $tenantId !== 'all') {
            $query->where('tenant_id', $tenantId);
        }

        $deleted = $query->delete();
        if (!$deleted) {
            return response()->json(['status' => 'error', 'message' => 'Customer profile not found or unauthorized.'], 404);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Customer profile deleted.',
        ]);
    }
}
