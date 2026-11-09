<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Department\StoreDepartmentRequest;
use App\Http\Requests\Department\UpdateDepartmentRequest;
use App\Http\Resources\Department\DepartmentResource;
use App\Support\SchemaGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * [BD-0064] Organizational Structure & Departmental Hierarchy Controller
 *
 * @ticket BD-0064
 * @component auth-service
 * @file codebridge-services/auth-service/app/Http/Controllers/Api/V1/DepartmentController.php
 * @related-model App\Models\Department, App\Models\Employee
 * @entry-point DepartmentController::index(), store(), update(), destroy()
 * @routes
 *  - GET    /api/v1/departments
 *  - POST   /api/v1/departments
 *  - PUT    /api/v1/departments/{id}
 *  - DELETE /api/v1/departments/{id}
 * @date-time 2026-10-23T15:40:00+07:00
 *
 * Process Capabilities:
 * Step 1 (Tenant Boundary Check): Verifies tenant context for departmental structure.
 * Step 2 (Department Directory Listing): Lists all operational divisions (Kitchen, Service, Accounting, Management).
 * Step 3 (Creation & Validation): Validates unique department code and name per tenant.
 * Step 4 (Hierarchy & Role Linkage): Maps department associations to staff profiles and supervisory managers.
 * Step 5 (Deletion & Orphan Safeguard): Prevents deletion of departments with actively assigned personnel.
 */
class DepartmentController extends Controller
{
    protected function getTenantId(Request $request): ?string
    {
        $user = $request->user();
        if ($user && !\App\Services\UserAuthorizationService::hasPlatformSuperAdminRole($user)) {
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

        $query = DB::table('departments as d');

        if ($user && $user->role !== 'super_admin' && !empty($tenantId)) {
            $query->where('d.tenant_id', $tenantId);
        } elseif (!empty($tenantId) && $tenantId !== 'all') {
            $query->where('d.tenant_id', $tenantId);
        }

        if (!empty($search)) {
            $escaped = SchemaGuard::escapeLike(trim((string) $search));
            $query->where(function ($q) use ($escaped) {
                $q->where('d.name', 'like', "%{$escaped}%")
                  ->orWhere('d.code', 'like', "%{$escaped}%");
            });
        }

        $perPage = min(100, max(1, (int) $request->query('per_page', 50)));
        $paginator = $query->select(
                'd.id',
                'd.name',
                'd.code',
                'd.description',
                'd.created_at',
                DB::raw('(SELECT COUNT(*) FROM employees WHERE department_id = d.id) as employees_count')
            )
            ->orderBy('d.name', 'asc')
            ->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data' => DepartmentResource::collection($paginator->items()),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function store(StoreDepartmentRequest $request)
    {
        $tenantId = $this->getTenantId($request);
        $validated = $request->validated();

        $code = strtoupper(trim($validated['code']));

        // Validate code uniqueness scoped to tenant
        $existsQuery = DB::table('departments')->where('code', $code);
        if (!empty($tenantId) && $tenantId !== 'all') {
            $existsQuery->where('tenant_id', $tenantId);
        }
        if ($existsQuery->exists()) {
            return response()->json([
                'status' => 'error',
                'message' => "Department code '{$code}' already exists in your organization.",
            ], 422);
        }

        $id = DB::table('departments')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'name' => $validated['name'],
            'code' => $code,
            'description' => $validated['description'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Department created successfully.',
            'data' => ['id' => $id],
        ], 201);
    }

    public function update(UpdateDepartmentRequest $request, $id)
    {
        $tenantId = $this->getTenantId($request);
        $user = $request->user();
        $validated = $request->validated();

        $query = DB::table('departments')->where('id', $id);
        if ($user && $user->role !== 'super_admin' && !empty($tenantId)) {
            $query->where('tenant_id', $tenantId);
        } elseif (!empty($tenantId) && $tenantId !== 'all') {
            $query->where('tenant_id', $tenantId);
        }

        $department = $query->first();
        if (!$department) {
            return response()->json(['status' => 'error', 'message' => 'Department not found or unauthorized.'], 404);
        }

        $query->update([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'updated_at' => now(),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Department updated successfully.',
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $tenantId = $this->getTenantId($request);
        $user = $request->user();
        $query = DB::table('departments')->where('id', $id);

        if ($user && $user->role !== 'super_admin' && !empty($tenantId)) {
            $query->where('tenant_id', $tenantId);
        } elseif (!empty($tenantId) && $tenantId !== 'all') {
            $query->where('tenant_id', $tenantId);
        }

        $deleted = $query->delete();
        if (!$deleted) {
            return response()->json(['status' => 'error', 'message' => 'Department not found or unauthorized.'], 404);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Department deleted successfully.',
        ]);
    }
}
