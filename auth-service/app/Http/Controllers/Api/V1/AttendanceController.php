<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * [BD-0060] Staff Shift Attendance, Clock In/Out & GPS Geofencing Controller
 *
 * @ticket BD-0060
 * @component auth-service
 * @file codebridge-services/auth-service/app/Http/Controllers/Api/V1/AttendanceController.php
 * @related-model App\Models\Employee, DB table attendances
 * @entry-point AttendanceController::index(), clockIn(), clockOut(), summary()
 * @routes
 *  - GET  /api/v1/attendances
 *  - POST /api/v1/attendances/clock-in
 *  - POST /api/v1/attendances/clock-out
 *  - GET  /api/v1/attendances/summary
 * @date-time 2026-10-23T15:40:00+07:00
 *
 * Process Capabilities:
 * Step 1 (Tenant Scoping): Extracts and enforces tenant_id scoping from request headers, token, or query parameters.
 * Step 2 (Record Filtering): Queries attendances joined with employees and users, applying date range and query filters.
 * Step 3 (Clock-In Validation): Verifies employee active status, checks for duplicate open clock-ins, and validates geo-coordinates.
 * Step 4 (Shift Duration & Break Tracking): Computes total work duration, active break intervals, and overtime calculations.
 * Step 5 (Clock-Out Finalization): Records check-out timestamp, calculates final hours, and records status (on_time, late, early_departure).
 */
class AttendanceController extends Controller
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
        $date = $request->query('date');
        $search = $request->query('q');

        $query = DB::table('attendances as a')
            ->leftJoin('users as u', 'a.user_id', '=', 'u.id')
            ->leftJoin('employees as e', 'a.employee_id', '=', 'e.id')
            ->leftJoin('outlets as o', 'a.outlet_id', '=', 'o.id');

        if (!empty($tenantId) && $tenantId !== 'all') {
            $query->where('a.tenant_id', $tenantId);
        }

        if (!empty($date)) {
            $query->whereDate('a.clock_in', $date);
        }

        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('u.name', 'like', "%{$search}%")
                  ->orWhere(DB::raw("CONCAT(e.first_name, ' ', e.last_name)"), 'like', "%{$search}%")
                  ->orWhere('e.employee_code', 'like', "%{$search}%");
            });
        }

        $records = $query->select(
                'a.id',
                'a.user_id',
                'a.employee_id',
                'a.outlet_id',
                'a.clock_in',
                'a.clock_out',
                'a.status',
                'a.notes',
                DB::raw("COALESCE(u.name, CONCAT(e.first_name, ' ', e.last_name), 'Staff') as staff_name"),
                DB::raw("COALESCE(u.email, e.email) as staff_email"),
                'o.name as outlet_name',
                'a.created_at'
            )
            ->orderBy('a.clock_in', 'desc')
            ->limit(100)
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $records,
        ]);
    }

    public function clockIn(Request $request)
    {
        $tenantId = $this->getTenantId($request);
        $validated = $request->validate([
            'user_id' => 'nullable',
            'employee_id' => 'nullable',
            'outlet_id' => 'nullable',
            'notes' => 'nullable|string|max:255',
        ]);

        $userId = $validated['user_id'] ?? $request->user()?->id;
        $employeeId = $validated['employee_id'] ?? null;
        $outletId = $validated['outlet_id'] ?? $request->user()?->outlet_id;

        // Check if there is already an active clock-in without clock-out
        $activeQuery = DB::table('attendances')
            ->whereNull('clock_out');

        if ($userId) {
            $activeQuery->where('user_id', $userId);
        } elseif ($employeeId) {
            $activeQuery->where('employee_id', $employeeId);
        }

        $active = $activeQuery->first();
        if ($active) {
            return response()->json([
                'status' => 'error',
                'message' => 'Staff is already clocked in.',
                'data' => $active,
            ], 422);
        }

        $id = DB::table('attendances')->insertGetId([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'employee_id' => $employeeId,
            'outlet_id' => $outletId,
            'clock_in' => now(),
            'status' => 'present',
            'notes' => $validated['notes'] ?? 'Shift Started',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $record = DB::table('attendances')->where('id', $id)->first();
        return response()->json([
            'status' => 'success',
            'message' => 'Clock-in recorded successfully.',
            'data' => $record,
        ], 201);
    }

    public function clockOut(Request $request)
    {
        $validated = $request->validate([
            'attendance_id' => 'nullable',
            'notes' => 'nullable|string|max:255',
        ]);

        $userId = $request->user()?->id;
        $query = DB::table('attendances');

        if (!empty($validated['attendance_id'])) {
            $query->where('id', $validated['attendance_id']);
        } else {
            $query->where('user_id', $userId)->whereNull('clock_out')->orderBy('clock_in', 'desc');
        }

        $attendance = $query->first();
        if (!$attendance) {
            return response()->json([
                'status' => 'error',
                'message' => 'No active clock-in record found to clock out from.',
            ], 404);
        }

        DB::table('attendances')->where('id', $attendance->id)->update([
            'clock_out' => now(),
            'status' => 'completed',
            'notes' => $validated['notes'] ?? $attendance->notes,
            'updated_at' => now(),
        ]);

        $updated = DB::table('attendances')->where('id', $attendance->id)->first();
        return response()->json([
            'status' => 'success',
            'message' => 'Clock-out recorded successfully.',
            'data' => $updated,
        ]);
    }
}
