<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Role\StoreRoleRequest;
use App\Http\Requests\Role\UpdateRoleRequest;
use App\Http\Resources\Role\RoleResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * [BD-0005] Dynamic RBAC Roles & Granular Permissions Controller.
 *
 * @ticket BD-0005
 * @component auth-service
 * @file codebridge-services/auth-service/app/Http/Controllers/Api/V1/RoleController.php
 * @related-model App\Models\Role, App\Models\Permission, App\Models\UserRole
 * @entry-point GET /api/v1/roles, POST /api/v1/roles, PUT /api/v1/roles/{id}
 * @date-time 2026-10-23T15:40:00+07:00
 *
 * Process Capabilities:
 * 1. Manages system default and custom tenant roles.
 * 2. Maps granular permission bits to roles via role_permissions pivot.
 * 3. Prevents modification or deletion of core system immutable roles.
 */
class RoleController extends Controller
{
    /**
     * List all dynamic roles with permission counts and pivot assignments.
     */
    public function index(Request $request)
    {
        $currentUser = $request->user();
        $query = DB::table('roles');

        if ($currentUser && $currentUser->role !== 'super_admin' && !empty($currentUser->tenant_id)) {
            $query->where(function ($q) use ($currentUser) {
                $q->whereNull('company_id')->orWhere('company_id', $currentUser->tenant_id);
            });
        }

        $roles = $query->orderBy('is_system', 'desc')
            ->orderBy('name', 'asc')
            ->get();

        foreach ($roles as $role) {
            $role->is_system = (bool) $role->is_system;

            $permissionIds = DB::table('role_permissions')
                ->where('role_id', $role->id)
                ->pluck('permission_id')
                ->toArray();

            $role->permission_ids = $permissionIds;
            $role->permissions_count = count($permissionIds);
        }

        return response()->json([
            'success' => true,
            'data' => RoleResource::collection($roles),
        ]);
    }

    public function store(StoreRoleRequest $request)
    {

        $currentUser = $request->user();
        $slug = \Illuminate\Support\Str::slug($request->name, '_');

        // Ensure unique slug
        $existing = DB::table('roles')->where('slug', $slug)->first();
        if ($existing) {
            $slug .= '_' . substr(md5(microtime()), 0, 4);
        }

        $companyId = ($currentUser && $currentUser->role === 'super_admin')
            ? ($request->company_id ?? $currentUser->tenant_id)
            : ($currentUser?->tenant_id ?? null);
        $parentRole = $request->parent_role ?? 'employee';

        $insertData = [
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'tenant_id' => $companyId,
            'company_id' => $companyId,
            'name' => $request->name,
            'slug' => $slug,
            'description' => $request->description,
            'is_system' => false,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        if (\Illuminate\Support\Facades\Schema::hasColumn('roles', 'parent_role')) {
            $insertData['parent_role'] = $parentRole;
        }

        $id = DB::table('roles')->insertGetId($insertData);

        if ($request->has('permission_ids') && is_array($request->permission_ids)) {
            foreach ($request->permission_ids as $permId) {
                DB::table('role_permissions')->insert([
                    'role_id' => $id,
                    'permission_id' => $permId,
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Custom role created successfully.',
            'data' => DB::table('roles')->where('id', $id)->first(),
        ], 201);
    }

    /**
     * View single role details with permissions.
     */
    public function show($id)
    {
        $role = DB::table('roles')->where('id', $id)->first();
        if (!$role) {
            return response()->json(['success' => false, 'message' => 'Role not found.'], 404);
        }

        $role->is_system = (bool) $role->is_system;

        $permissions = DB::table('role_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('role_permissions.role_id', $id)
            ->select('permissions.*')
            ->get();

        $role->permissions = $permissions;
        $role->permission_ids = $permissions->pluck('id')->toArray();

        return response()->json([
            'success' => true,
            'data' => $role,
        ]);
    }

    /**
     * Update an existing custom role and sync permission assignments.
     */
    public function update(UpdateRoleRequest $request, $id)
    {
        $role = DB::table('roles')->where('id', $id)->first();
        if (!$role) {
            return response()->json(['success' => false, 'message' => 'Role not found.'], 404);
        }

        if ($role->is_system) {
            return response()->json(['success' => false, 'message' => 'System default roles cannot be modified.'], 422);
        }

        $currentUser = $request->user();
        if ($currentUser && $currentUser->role !== 'super_admin' && !empty($currentUser->tenant_id)) {
            if ($role->company_id && $role->company_id != $currentUser->tenant_id) {
                return response()->json(['success' => false, 'message' => 'Unauthorized to modify role for another organization.'], 403);
            }
        }

        $updateData = ['updated_at' => now()];
        if ($request->has('name')) {
            $updateData['name'] = $request->name;
        }
        if ($request->has('description')) {
            $updateData['description'] = $request->description;
        }

        DB::table('roles')->where('id', $id)->update($updateData);

        if ($request->has('permission_ids')) {
            DB::table('role_permissions')->where('role_id', $id)->delete();

            if (is_array($request->permission_ids)) {
                foreach ($request->permission_ids as $permId) {
                    DB::table('role_permissions')->insert([
                        'role_id' => $id,
                        'permission_id' => $permId,
                    ]);
                }
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Role permissions updated successfully.',
        ]);
    }

    /**
     * Delete a custom role (protect system roles).
     */
    public function destroy(Request $request, $id)
    {
        $role = DB::table('roles')->where('id', $id)->first();
        if (!$role) {
            return response()->json(['success' => false, 'message' => 'Role not found.'], 404);
        }

        if ($role->is_system) {
            return response()->json(['success' => false, 'message' => 'System default roles cannot be deleted.'], 422);
        }

        $currentUser = $request->user();
        if ($currentUser && $currentUser->role !== 'super_admin' && !empty($currentUser->tenant_id)) {
            if ($role->company_id && $role->company_id != $currentUser->tenant_id) {
                return response()->json(['success' => false, 'message' => 'Unauthorized to delete role for another organization.'], 403);
            }
        }

        DB::table('role_permissions')->where('role_id', $id)->delete();
        DB::table('roles')->where('id', $id)->delete();

        return response()->json([
            'success' => true,
            'message' => 'Custom role deleted successfully.',
        ]);
    }
}
