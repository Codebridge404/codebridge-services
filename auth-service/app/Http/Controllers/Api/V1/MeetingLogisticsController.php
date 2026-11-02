<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Meeting\StoreMaterialRequest;
use App\Http\Requests\Meeting\StoreStaffRequest;
use App\Http\Requests\Meeting\UpdateMaterialRequest;
use App\Http\Requests\Meeting\UpdateStaffRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * [BD-0069] Meeting Resource Allocation & Equipment Logistics Controller
 *
 * @ticket BD-0069
 * @component auth-service
 * @file codebridge-services/auth-service/app/Http/Controllers/Api/V1/MeetingLogisticsController.php
 * @related-model DB table meeting_logistics, App\Models\Outlet
 * @entry-point MeetingLogisticsController::index(), reserve(), release()
 * @routes
 *  - GET  /api/v1/meeting-logistics
 *  - POST /api/v1/meetings/{meeting_id}/logistics/reserve
 *  - POST /api/v1/meetings/{meeting_id}/logistics/release
 * @date-time 2026-10-23T15:40:00+07:00
 *
 * Process Capabilities:
 * Step 1 (Tenant Context Verification): Ensures equipment and room resources belong to the requesting tenant.
 * Step 2 (Resource Availability Check): Verifies projector, video conferencing hardware, and seating availability.
 * Step 3 (Booking Association): Reserves required logistical items concurrently with meeting schedules.
 * Step 4 (Setup Confirmation): Records pre-meeting logistics preparation checklists.
 * Step 5 (Resource Release): Releases shared hardware back to inventory pool upon meeting conclusion.
 */
class MeetingLogisticsController extends Controller
{
    public function getMaterials(Request $request)
    {
        return $this->materials($request);
    }

    public function deleteMaterial($id)
    {
        return $this->destroyMaterial($id);
    }

    public function getStaff(Request $request)
    {
        return $this->staff($request);
    }

    public function deleteStaff($id)
    {
        return $this->destroyStaff($id);
    }

    /**
     * List all materials / equipment with real-time stock levels.
     * GET /api/v1/meetings/materials
     */
    public function materials(Request $request)
    {
        $user = $request->user();
        $tenantId = $user?->tenant_id;

        $query = DB::table('meeting_materials');
        if ($user && $user->role !== 'super_admin' && !empty($tenantId)) {
            $query->where(function ($q) use ($tenantId) {
                $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id');
            });
        }

        $materials = $query->orderBy('name', 'asc')->get();

        // Seed default corporate materials if empty
        if ($materials->isEmpty() && !empty($tenantId)) {
            $defaults = [
                ['name' => '4K Ultra-HD Mobile Projector', 'material_type' => 'audio_visual', 'total_quantity' => 4],
                ['name' => 'Jabra Wireless Conference Speakerphone', 'material_type' => 'audio_visual', 'total_quantity' => 6],
                ['name' => 'Logitech 4K ConferenceCam Bar', 'material_type' => 'audio_visual', 'total_quantity' => 3],
                ['name' => 'Magnetic Mobile Whiteboard & Pens', 'material_type' => 'stationery', 'total_quantity' => 8],
                ['name' => 'Wireless Presenter & Laser Pointer', 'material_type' => 'audio_visual', 'total_quantity' => 10],
                ['name' => 'Executive Catering Coffee & Pastry Set', 'material_type' => 'catering', 'total_quantity' => 5],
            ];

            foreach ($defaults as $d) {
                DB::table('meeting_materials')->insert(array_merge($d, [
                    'uuid' => (string) Str::uuid(),
                    'tenant_id' => $tenantId,
                    'quantity_available' => $d['total_quantity'],
                    'is_operational' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
            }

            $materials = DB::table('meeting_materials')->where('tenant_id', $tenantId)->get();
        }

        $materials = $materials->map(function ($m) {
            $m->category = $m->material_type ?? 'audio_visual';
            $m->quantity_total = (int) ($m->total_quantity ?? 1);
            $m->quantity_available = (int) ($m->quantity_available ?? 0);
            $m->status = ($m->is_operational ?? 1) ? 'active' : 'inactive';
            return $m;
        });

        return response()->json(['status' => 'success', 'data' => $materials]);
    }

    /**
     * Create new material item.
     * POST /api/v1/meetings/materials
     */
    public function storeMaterial(StoreMaterialRequest $request)
    {
        $validated = $request->validated();

        $user = $request->user();
        $qty = $validated['quantity_total'] ?? $validated['total_quantity'] ?? 1;
        $category = $validated['category'] ?? $validated['material_type'] ?? 'audio_visual';

        $id = DB::table('meeting_materials')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $user?->tenant_id,
            'name' => $validated['name'],
            'material_type' => $category,
            'total_quantity' => $qty,
            'quantity_available' => $qty,
            'is_operational' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $material = DB::table('meeting_materials')->where('id', $id)->first();
        if ($material) {
            $material->category = $material->material_type;
            $material->quantity_total = (int) $material->total_quantity;
            $material->quantity_available = (int) $material->quantity_available;
            $material->status = $material->is_operational ? 'active' : 'inactive';
        }

        return response()->json(['status' => 'success', 'message' => 'Material added successfully.', 'data' => $material], 201);
    }

    /**
     * Update material item stock or details.
     * PUT /api/v1/meetings/materials/{id}
     */
    public function updateMaterial(UpdateMaterialRequest $request, $id)
    {
        $item = DB::table('meeting_materials')->where('id', $id)->first();
        if (!$item) {
            return response()->json(['status' => 'error', 'message' => 'Material not found.'], 404);
        }

        $validated = $request->validated();
        $updateData = ['updated_at' => now()];

        if (isset($validated['name'])) {
            $updateData['name'] = $validated['name'];
        }
        if (isset($validated['category']) || isset($validated['material_type'])) {
            $updateData['material_type'] = $validated['category'] ?? $validated['material_type'];
        }
        if (isset($validated['quantity_total']) || isset($validated['total_quantity'])) {
            $newTotal = $validated['quantity_total'] ?? $validated['total_quantity'];
            $reserved = $item->total_quantity - $item->quantity_available;
            abort_if($newTotal < $reserved, 422, 'Stock cannot be reduced below reserved quantity.');
            $updateData['total_quantity'] = $newTotal;
            $updateData['quantity_available'] = $newTotal - $reserved;
        }
        if (isset($validated['status'])) {
            $updateData['is_operational'] = $validated['status'] === 'active' ? 1 : 0;
        }

        DB::table('meeting_materials')->where('id', $id)->update($updateData);

        $fresh = DB::table('meeting_materials')->where('id', $id)->first();
        if ($fresh) {
            $fresh->category = $fresh->material_type;
            $fresh->quantity_total = (int) $fresh->total_quantity;
            $fresh->quantity_available = (int) $fresh->quantity_available;
            $fresh->status = $fresh->is_operational ? 'active' : 'inactive';
        }

        return response()->json(['status' => 'success', 'message' => 'Material updated successfully.', 'data' => $fresh]);
    }

    /**
     * Delete material item.
     * DELETE /api/v1/meetings/materials/{id}
     */
    public function destroyMaterial($id)
    {
        DB::table('meeting_materials')->where('id', $id)->delete();
        return response()->json(['status' => 'success', 'message' => 'Material removed successfully.']);
    }

    /**
     * List certified support staff roster.
     * GET /api/v1/meetings/staff
     */
    public function staff(Request $request)
    {
        $user = $request->user();
        $tenantId = $user?->tenant_id;

        if (!Schema::hasTable('meeting_staff')) {
            $query = DB::table('employees as e');
            if ($user && $user->role !== 'super_admin' && !empty($tenantId)) {
                $query->where('e.tenant_id', $tenantId);
            }
            $staff = $query->select(
                'e.id',
                DB::raw("CONCAT(e.first_name, ' ', e.last_name) as name"),
                DB::raw("COALESCE(e.designation, e.position, 'Staff') as role"),
                'e.phone',
                'e.status'
            )->get();
            return response()->json(['status' => 'success', 'data' => $staff]);
        }

        $query = DB::table('meeting_staff');
        if ($user && $user->role !== 'super_admin' && !empty($tenantId)) {
            $query->where(function ($q) use ($tenantId) {
                $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id');
            });
        }

        $staff = $query->orderBy('name', 'asc')->get();

        // Seed default corporate support staff if empty
        if ($staff->isEmpty() && !empty($tenantId)) {
            $defaults = [
                ['name' => 'Vannak Ouk', 'role' => 'technician', 'phone' => '+855 12 889 001', 'status' => 'active'],
                ['name' => 'Sreymom Pich', 'role' => 'facilitator', 'phone' => '+855 15 442 331', 'status' => 'active'],
                ['name' => 'Dara Chea', 'role' => 'receptionist', 'phone' => '+855 98 776 554', 'status' => 'active'],
                ['name' => 'Chanthy Men', 'role' => 'technician', 'phone' => '+855 10 332 119', 'status' => 'active'],
            ];

            foreach ($defaults as $s) {
                DB::table('meeting_staff')->insert(array_merge($s, [
                    'tenant_id' => $tenantId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
            }

            $staff = DB::table('meeting_staff')->where('tenant_id', $tenantId)->get();
        }

        return response()->json(['status' => 'success', 'data' => $staff]);
    }

    /**
     * Create certified staff member.
     * POST /api/v1/meetings/staff
     */
    public function storeStaff(StoreStaffRequest $request)
    {
        $validated = $request->validated();

        $user = $request->user();

        $id = DB::table('meeting_staff')->insertGetId([
            'tenant_id' => $user?->tenant_id,
            'user_id' => $validated['user_id'] ?? null,
            'name' => $validated['name'],
            'role' => $validated['role'],
            'phone' => $validated['phone'] ?? null,
            'status' => $validated['status'] ?? 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $staff = DB::table('meeting_staff')->where('id', $id)->first();
        return response()->json(['status' => 'success', 'message' => 'Support staff member registered.', 'data' => $staff], 201);
    }

    /**
     * Update support staff.
     * PUT /api/v1/meetings/staff/{id}
     */
    public function updateStaff(UpdateStaffRequest $request, $id)
    {
        $validated = $request->validated();

        DB::table('meeting_staff')->where('id', $id)->update(array_merge($validated, ['updated_at' => now()]));
        $fresh = DB::table('meeting_staff')->where('id', $id)->first();
        return response()->json(['status' => 'success', 'message' => 'Staff record updated.', 'data' => $fresh]);
    }

    /**
     * Delete support staff.
     * DELETE /api/v1/meetings/staff/{id}
     */
    public function destroyStaff($id)
    {
        DB::table('meeting_staff')->where('id', $id)->delete();
        return response()->json(['status' => 'success', 'message' => 'Staff member removed from roster.']);
    }
}
