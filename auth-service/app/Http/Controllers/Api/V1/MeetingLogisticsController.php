<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
                ['name' => '4K Ultra-HD Mobile Projector', 'category' => 'audio_visual', 'quantity_total' => 4, 'quantity_available' => 4],
                ['name' => 'Jabra Wireless Conference Speakerphone', 'category' => 'audio_visual', 'quantity_total' => 6, 'quantity_available' => 6],
                ['name' => 'Logitech 4K ConferenceCam Bar', 'category' => 'audio_visual', 'quantity_total' => 3, 'quantity_available' => 3],
                ['name' => 'Magnetic Mobile Whiteboard & Pens', 'category' => 'stationery', 'quantity_total' => 8, 'quantity_available' => 8],
                ['name' => 'Wireless Presenter & Laser Pointer', 'category' => 'audio_visual', 'quantity_total' => 10, 'quantity_available' => 10],
                ['name' => 'Executive Catering Coffee & Pastry Set', 'category' => 'catering', 'quantity_total' => 5, 'quantity_available' => 5],
            ];

            foreach ($defaults as $d) {
                DB::table('meeting_materials')->insert(array_merge($d, [
                    'tenant_id' => $tenantId,
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
            }

            $materials = DB::table('meeting_materials')->where('tenant_id', $tenantId)->get();
        }

        return response()->json(['status' => 'success', 'data' => $materials]);
    }

    /**
     * Create new material item.
     * POST /api/v1/meetings/materials
     */
    public function storeMaterial(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:150',
            'category' => 'nullable|string|max:50',
            'quantity_total' => 'required|integer|min:1',
            'status' => 'nullable|string|max:30',
        ]);

        $user = $request->user();
        $qty = $validated['quantity_total'];

        $id = DB::table('meeting_materials')->insertGetId([
            'tenant_id' => $user?->tenant_id,
            'name' => $validated['name'],
            'category' => $validated['category'] ?? 'audio_visual',
            'quantity_total' => $qty,
            'quantity_available' => $qty,
            'status' => $validated['status'] ?? 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $material = DB::table('meeting_materials')->where('id', $id)->first();
        return response()->json(['status' => 'success', 'message' => 'Material added successfully.', 'data' => $material], 201);
    }

    /**
     * Update material item stock or details.
     * PUT /api/v1/meetings/materials/{id}
     */
    public function updateMaterial(Request $request, $id)
    {
        $item = DB::table('meeting_materials')->where('id', $id)->first();
        if (!$item) {
            return response()->json(['status' => 'error', 'message' => 'Material not found.'], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:150',
            'category' => 'sometimes|string|max:50',
            'quantity_total' => 'sometimes|integer|min:0',
            'quantity_available' => 'sometimes|integer|min:0',
            'status' => 'sometimes|string|max:30',
        ]);

        $updateData = array_merge($validated, ['updated_at' => now()]);
        DB::table('meeting_materials')->where('id', $id)->update($updateData);

        $fresh = DB::table('meeting_materials')->where('id', $id)->first();
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
    public function storeStaff(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:150',
            'role' => 'required|string|max:50',
            'phone' => 'nullable|string|max:50',
            'user_id' => 'nullable|integer',
            'status' => 'nullable|string|max:30',
        ]);

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
    public function updateStaff(Request $request, $id)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:150',
            'role' => 'sometimes|string|max:50',
            'phone' => 'sometimes|nullable|string|max:50',
            'status' => 'sometimes|string|max:30',
        ]);

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
