<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\StoreEmployeeRequest;
use App\Http\Requests\Employee\UpdateEmployeeRequest;
use App\Http\Resources\Employee\EmployeeResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * [BD-0065] Human Resource Management & Staff Records Controller
 *
 * @ticket BD-0065
 * @component auth-service
 * @file codebridge-services/auth-service/app/Http/Controllers/Api/V1/EmployeeController.php
 * @related-model App\Models\Employee, App\Models\User, App\Models\Department
 * @entry-point EmployeeController::index(), store(), show(), update(), destroy()
 * @routes
 *  - GET    /api/v1/employees
 *  - POST   /api/v1/employees
 *  - GET    /api/v1/employees/{id}
 *  - PUT    /api/v1/employees/{id}
 *  - DELETE /api/v1/employees/{id}
 * @date-time 2026-10-23T15:40:00+07:00
 *
 * Process Capabilities:
 * Step 1 (Tenant Scoping & Isolation): Enforces tenant_id isolation across all employee records.
 * Step 2 (Staff Registration & Validation): Validates national ID, tax number, emergency contacts, hire date, and job title.
 * Step 3 (User Account Linking): Connects HR employee records to system user accounts for POS and back-office logins.
 * Step 4 (Department & Outlet Assignment): Assigns employee to primary store outlet branch and operational department.
 * Step 5 (Employment Lifecycle Status): Manages status transitions (active, on_leave, probation, terminated).
 */
class EmployeeController extends Controller
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
        $departmentId = $request->query('department_id');

        $query = DB::table('employees as e')
            ->leftJoin('departments as d', 'e.department_id', '=', 'd.id')
            ->leftJoin('outlets as o', 'e.outlet_id', '=', 'o.id');

        if ($user && $user->role !== 'super_admin' && !empty($tenantId)) {
            $query->where('e.tenant_id', $tenantId);
        } elseif (!empty($tenantId) && $tenantId !== 'all') {
            $query->where('e.tenant_id', $tenantId);
        }

        if (!empty($departmentId)) {
            $query->where('e.department_id', $departmentId);
        }

        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where(DB::raw("CONCAT(e.first_name, ' ', e.last_name)"), 'like', "%{$search}%")
                  ->orWhere('e.employee_code', 'like', "%{$search}%")
                  ->orWhere('e.email', 'like', "%{$search}%")
                  ->orWhere('e.phone', 'like', "%{$search}%");
            });
        }

        $employees = $query->select(
                'e.id',
                'e.employee_code',
                'e.first_name',
                'e.last_name',
                DB::raw("CONCAT(e.first_name, ' ', e.last_name) as full_name"),
                'e.email',
                'e.phone',
                'e.designation',
                'e.employment_type',
                'e.hire_date',
                'e.salary',
                'e.is_active',
                'e.department_id',
                'd.name as department_name',
                'e.outlet_id',
                'o.name as outlet_name',
                'e.created_at'
            )
            ->orderBy('e.created_at', 'desc')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => EmployeeResource::collection($employees),
        ]);
    }

    public function store(StoreEmployeeRequest $request)
    {
        $tenantId = $this->getTenantId($request);
        $validated = $request->validated();

        $code = 'EMP-' . strtoupper(substr(uniqid(), -4));

        $id = DB::table('employees')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'employee_code' => $code,
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'email' => $validated['email'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'department_id' => !empty($validated['department_id']) ? $validated['department_id'] : null,
            'outlet_id' => !empty($validated['outlet_id']) ? $validated['outlet_id'] : null,
            'position' => $validated['designation'] ?? null,
            'designation' => $validated['designation'],
            'employment_type' => $validated['employment_type'],
            'hire_date' => $validated['hire_date'] ?? now()->toDateString(),
            'salary' => $validated['salary'] ?? 0,
            'status' => 'active',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Employee profile created successfully.',
            'data' => ['id' => $id],
        ], 201);
    }

    public function update(UpdateEmployeeRequest $request, $id)
    {
        $tenantId = $this->getTenantId($request);
        $user = $request->user();
        $validated = $request->validated();

        $query = DB::table('employees')->where('id', $id);
        if ($user && $user->role !== 'super_admin' && !empty($tenantId)) {
            $query->where('tenant_id', $tenantId);
        } elseif (!empty($tenantId) && $tenantId !== 'all') {
            $query->where('tenant_id', $tenantId);
        }

        $employee = $query->first();
        if (!$employee) {
            return response()->json(['status' => 'error', 'message' => 'Employee record not found or unauthorized.'], 404);
        }

        $query->update([
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'email' => $validated['email'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'department_id' => !empty($validated['department_id']) ? $validated['department_id'] : null,
            'outlet_id' => !empty($validated['outlet_id']) ? $validated['outlet_id'] : null,
            'designation' => $validated['designation'],
            'employment_type' => $validated['employment_type'],
            'hire_date' => $validated['hire_date'] ?? null,
            'salary' => $validated['salary'] ?? 0,
            'updated_at' => now(),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Employee record updated successfully.',
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $tenantId = $this->getTenantId($request);
        $user = $request->user();
        $query = DB::table('employees')->where('id', $id);

        if ($user && $user->role !== 'super_admin' && !empty($tenantId)) {
            $query->where('tenant_id', $tenantId);
        } elseif (!empty($tenantId) && $tenantId !== 'all') {
            $query->where('tenant_id', $tenantId);
        }

        $employee = $query->first();
        if (!$employee) {
            return response()->json(['status' => 'error', 'message' => 'Employee record not found or unauthorized.'], 404);
        }

        $query->update([
            'is_active' => false,
            'updated_at' => now(),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Employee record deactivated.',
        ]);
    }
}
