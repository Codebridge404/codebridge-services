<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\TenantAuthorizationService;
use App\Traits\ResolvesTenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * [BD-0103] Dine-in Floor Plan, Table Status & Seating Management Controller
 *
 * @ticket BD-0103
 * @component operations-service
 * @file codebridge-services/operations-service/app/Http/Controllers/Api/V1/TableController.php
 * @related-model DB table dining_tables, App\Models\Order
 * @entry-point TableController::index(), store(), update(), updateStatus(), transferTable()
 * @routes
 *  - GET  /api/v1/tables
 *  - POST /api/v1/tables
 *  - PUT  /api/v1/tables/{id}
 *  - POST /api/v1/tables/{id}/status
 *  - POST /api/v1/tables/{id}/transfer
 * @date-time 2026-10-23T15:40:00+07:00
 *
 * Process Capabilities:
 * Step 1 (Outlet Floor Plan Directory): Organizes dining tables into zones/rooms (Main Hall, Patio, VIP Room, Bar).
 * Step 2 (Real-Time Table Status): Monitors table states (available, occupied, billed, cleaning, reserved).
 * Step 3 (Guest Seating & Order Linking): Binds active order cart to specific table number and records guest cover count.
 * Step 4 (Table Transfer & Split): Supports moving orders between tables or splitting checks across guests.
 * Step 5 (Bill Print & Table Clearance): Marks table billed upon receipt printing and resets to available upon payment.
 */
class TableController extends Controller
{
    use ResolvesTenantContext;

    /**
     * Get Restaurant Floor Plan Tables
     */
    public function index(Request $request)
    {
        $tenantId = $this->getTenantId($request, true);
        $isSuperAdminAll = ($tenantId === 'all' && TenantAuthorizationService::hasPlatformSuperAdminRole($request->user()));
        $perPage = min(100, max(1, (int) $request->query('per_page', 50)));

        $query = DB::table('restaurant_tables')->orderBy('name', 'asc');
        if (!$isSuperAdminAll) {
            $query->where('tenant_id', $tenantId);
        }

        $paginator = $query->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data' => $paginator->items(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * Update Table Occupancy Status
     */
    public function updateStatus(Request $request, $id)
    {
        $this->getTenantId($request, true);

        $table = DB::table('restaurant_tables')->where('id', $id)->first();
        if (!$table) {
            return response()->json(['status' => 'error', 'message' => 'Table not found.'], 404);
        }

        $this->authorizeTenantResource($table->tenant_id, $request, 'Table');

        $validated = $request->validate([
            'status' => 'required|in:vacant,occupied,reserved,bill_requested',
        ]);

        DB::table('restaurant_tables')
            ->where('id', $id)
            ->when($table->tenant_id !== null, fn ($q) => $q->where('tenant_id', $table->tenant_id))
            ->update([
                'status' => $validated['status'],
                'updated_at' => now(),
            ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Table status updated.',
        ]);
    }

    /**
     * Create New Restaurant Table
     */
    public function store(Request $request)
    {
        $tenantId = $this->getTenantId($request, true);
        $writeTenantId = $tenantId === 'all' ? null : $tenantId;

        $validated = $request->validate([
            'name' => 'required|string|max:50',
            'zone' => 'nullable|string|max:50',
            'capacity' => 'nullable|integer|min:1|max:50',
            'outlet_id' => 'nullable|integer',
        ]);

        if (!empty($validated['outlet_id']) && $writeTenantId !== null) {
            $outlet = DB::table('outlets')->where('id', $validated['outlet_id'])->first();
            if ($outlet && $outlet->tenant_id !== null && (string) $outlet->tenant_id !== (string) $writeTenantId) {
                return response()->json(['status' => 'error', 'message' => 'Selected outlet belongs to another organization.'], 403);
            }
        }

        $id = DB::table('restaurant_tables')->insertGetId([
            'tenant_id' => $writeTenantId,
            'outlet_id' => $validated['outlet_id'] ?? ($request->user()?->outlet_id ?? 1),
            'name' => $validated['name'],
            'zone' => $validated['zone'] ?? 'Main Dining',
            'capacity' => $validated['capacity'] ?? 4,
            'status' => 'vacant',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $table = DB::table('restaurant_tables')->where('id', $id)->first();

        return response()->json([
            'status' => 'success',
            'data' => $table,
        ], 201);
    }
}
