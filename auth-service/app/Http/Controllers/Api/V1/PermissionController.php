<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * [BD-0075] Granular RBAC Permission Catalog & Authorization Controller
 *
 * @ticket BD-0075
 * @component auth-service
 * @file codebridge-services/auth-service/app/Http/Controllers/Api/V1/PermissionController.php
 * @related-model App\Models\Role, DB table permissions, DB table role_permissions
 * @entry-point PermissionController::index(), syncPermissions(), check()
 * @routes
 *  - GET  /api/v1/permissions
 *  - POST /api/v1/roles/{id}/permissions
 *  - GET  /api/v1/permissions/check
 * @date-time 2026-10-23T15:40:00+07:00
 *
 * Process Capabilities:
 * Step 1 (Permission Catalog Discovery): Returns standardized system permission matrix across all modules.
 * Step 2 (Category Grouping): Organizes permissions into operational groups (POS, Inventory, Billing, Users, Reports).
 * Step 3 (Role Permission Sync): Allows tenant administrators to assign and revoke permissions to custom roles.
 * Step 4 (Cache Invalidation): Invalidates distributed user permission caches upon any role permission change.
 * Step 5 (Privilege Escalation Prevention): Restricts tenant admins from assigning superadmin system permissions.
 */
class PermissionController extends Controller
{
    /**
     * List all available system permissions grouped by module.
     */
    public function index(Request $request)
    {
        $permissions = DB::table('permissions')
            ->orderBy('group', 'asc')
            ->orderBy('name', 'asc')
            ->get();

        $grouped = [];
        foreach ($permissions as $perm) {
            $grouped[$perm->group][] = $perm;
        }

        return response()->json([
            'success' => true,
            'data' => $permissions,
            'grouped' => $grouped,
        ]);
    }
}
