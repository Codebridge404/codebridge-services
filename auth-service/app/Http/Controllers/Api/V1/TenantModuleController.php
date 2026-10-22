<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TenantModuleController extends Controller
{
    /**
     * Get active module licenses for a tenant.
     * GET /api/v1/tenants/modules
     */
    public function index(Request $request)
    {
        $tenantId = $request->query('tenant_id');
        $orgName = $request->query('org_name');
        $authUser = $request->user();

        // Standalone user without tenant or unauthenticated
        if (!$authUser) {
            return response()->json([
                'success' => true,
                'modules' => [],
            ]);
        }

        $query = DB::table('tenants');

        if ($authUser->role === 'super_admin') {
            if ($tenantId) {
                $query->where('id', $tenantId);
            } elseif ($orgName) {
                $query->where('name', $orgName);
            } elseif ($authUser->tenant_id) {
                $query->where('id', $authUser->tenant_id);
            } else {
                return response()->json([
                    'success' => true,
                    'modules' => [],
                ]);
            }
        } else {
            // Non-super-admin is STRICTLY scoped to their own tenant
            if (!$authUser->tenant_id) {
                return response()->json([
                    'success'     => true,
                    'tenant_id'   => null,
                    'tenant_name' => null,
                    'modules'     => [],
                ]);
            }
            $query->where('id', $authUser->tenant_id);
        }

        $tenant = $query->first();

        if (!$tenant) {
            return response()->json([
                'success' => false,
                'modules' => [],
                'message' => 'Tenant not found.',
            ], 404);
        }

        $modules = $tenant->enabled_modules;
        if (is_string($modules)) {
            $modules = json_decode($modules, true);
        }

        if (!is_array($modules)) {
            $modules = [];
        }

        return response()->json([
            'success'     => true,
            'tenant_id'   => $tenant->id,
            'tenant_name' => $tenant->name,
            'modules'     => array_values($modules),
        ]);
    }

    /**
     * Update module license entitlements for a tenant.
     * PUT /api/v1/tenants/modules
     */
    public function update(Request $request)
    {
        $request->validate([
            'modules'   => 'present|array',
            'modules.*' => 'string',
        ]);

        $tenantId = $request->input('tenant_id');
        $orgName = $request->input('org_name');
        $authUser = $request->user();

        if (!$authUser) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        $query = DB::table('tenants');

        if ($authUser->role === 'super_admin') {
            if ($tenantId) {
                $query->where('id', $tenantId);
            } elseif ($orgName) {
                $query->where('name', $orgName);
            } elseif ($authUser->tenant_id) {
                $query->where('id', $authUser->tenant_id);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Please provide a valid tenant_id or org_name.',
                ], 422);
            }
        } else {
            if (!$authUser->tenant_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'No active organization to update modules.',
                ], 403);
            }
            $query->where('id', $authUser->tenant_id);
        }

        $tenant = $query->first();

        if (!$tenant) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant not found to update module entitlements.',
            ], 404);
        }

        $modules = array_values(array_unique($request->input('modules')));

        DB::table('tenants')->where('id', $tenant->id)->update([
            'enabled_modules' => json_encode($modules),
            'updated_at'      => now(),
        ]);

        // Record audit event
        if ($authUser && class_exists(AuditLogController::class)) {
            AuditLogController::log(
                $authUser->id,
                $authUser->name,
                'update_tenant_module_licenses',
                'tenants',
                $request->ip(),
                [
                    'tenant_id'   => $tenant->id,
                    'tenant_name' => $tenant->name,
                    'modules'     => $modules,
                ]
            );
        }

        return response()->json([
            'success'     => true,
            'message'     => "Successfully updated module licenses for {$tenant->name}.",
            'tenant_id'   => $tenant->id,
            'tenant_name' => $tenant->name,
            'modules'     => $modules,
        ]);
    }
}
