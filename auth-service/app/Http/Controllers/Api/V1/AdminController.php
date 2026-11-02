<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\User\UserResource;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * [BD-0090] Unified Role-Based Administrative Controller.
 *
 * Provides administrative insights, user directories, role governance, and tenant scoping
 * dynamically conditioned on the caller's role hierarchy:
 * - super_admin: Platform-wide governance across all tenants, infrastructure, and all users.
 * - organization / admin: Workspace-scoped governance (staff, outlets, quotas, subscriptions).
 * - outlet_manager / supervisor: Outlet-scoped store operations (store staff, POS registers, PINs).
 */
class AdminController extends Controller
{
    /**
     * Unified Role-Based Admin Dashboard Metrics.
     * GET /api/v1/admin/dashboard
     */
    public function dashboard(Request $request)
    {
        $currentUser = $request->user();
        $role = $currentUser?->role ?? 'user';
        $tenantId = $currentUser?->tenant_id;

        if ($role === 'super_admin') {
            return $this->superAdminDashboard();
        }

        if (in_array($role, ['organization', 'admin', 'administrator', 'owner'])) {
            return $this->organizationAdminDashboard($currentUser, $tenantId);
        }

        if (in_array($role, ['outlet_manager', 'supervisor'])) {
            return $this->outletManagerDashboard($currentUser, $tenantId);
        }

        return response()->json([
            'status' => 'error',
            'message' => 'Forbidden. Your role does not have administrative dashboard privileges.',
        ], 403);
    }

    /**
     * Super Admin Dashboard: Platform-wide multi-tenant governance metrics.
     */
    protected function superAdminDashboard()
    {
        $totalTenants = DB::table('tenants')->count();
        $activeTenants = DB::table('tenants')->where('status', 'active')->count();
        $trialTenants = DB::table('tenants')->where('status', 'trial')->orWhereNotNull('trial_ends_at')->count();

        $totalUsers = DB::table('users')->count();
        $activeUsers = DB::table('users')->where('is_active', true)->count();
        $totalOutlets = DB::table('outlets')->count();
        $totalRoles = DB::table('roles')->count();

        // Subscription tiers breakdown
        $tierBreakdown = DB::table('tenants')
            ->select('client_tier', DB::raw('count(*) as count'))
            ->groupBy('client_tier')
            ->pluck('count', 'client_tier')
            ->toArray();

        // Recent 5 tenants
        $recentTenants = DB::table('tenants')
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get(['id', 'uuid', 'name', 'slug', 'client_tier', 'status', 'created_at']);

        // Recent 10 platform audit logs
        $recentLogs = DB::table('audit_logs as al')
            ->leftJoin('users as u', 'al.user_id', '=', 'u.id')
            ->leftJoin('tenants as t', 'al.tenant_id', '=', 't.id')
            ->select('al.id', 'al.action', 'al.ip_address', 'al.created_at', 'u.name as user_name', 't.name as tenant_name')
            ->orderBy('al.created_at', 'desc')
            ->limit(10)
            ->get();

        return response()->json([
            'status' => 'success',
            'role' => 'super_admin',
            'scope' => 'platform',
            'data' => [
                'overview' => [
                    'total_tenants'  => $totalTenants,
                    'active_tenants' => $activeTenants,
                    'trial_tenants'  => $trialTenants,
                    'total_users'    => $totalUsers,
                    'active_users'   => $activeUsers,
                    'total_outlets'  => $totalOutlets,
                    'total_roles'    => $totalRoles,
                ],
                'tier_breakdown' => $tierBreakdown,
                'recent_tenants' => $recentTenants,
                'recent_activity'=> $recentLogs,
            ],
        ]);
    }

    /**
     * Organization Admin Dashboard: Workspace-scoped staff, outlet, and subscription metrics.
     */
    protected function organizationAdminDashboard($currentUser, $tenantId)
    {
        $tenant = $tenantId ? DB::table('tenants')->where('id', $tenantId)->first() : null;

        $tier = $tenant?->client_tier ?? 'business_runner';
        $maxUsers = $tenant?->max_users ?? ($tier === 'enterprise_org' ? 500 : ($tier === 'business_runner' ? 50 : 5));
        $maxOutlets = $tenant?->max_outlets ?? ($tier === 'enterprise_org' ? 50 : ($tier === 'business_runner' ? 5 : 1));

        $activeStaffCount = $tenantId ? DB::table('tenant_users')
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->count() : 0;

        $outletsCount = $tenantId ? DB::table('outlets')
            ->where('tenant_id', $tenantId)
            ->count() : 0;

        $subscription = $tenantId ? DB::table('tenant_subscriptions')
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->orderBy('created_at', 'desc')
            ->first() : null;

        $recentStaff = $tenantId ? DB::table('users as u')
            ->join('tenant_users as tu', function ($j) use ($tenantId) {
                $j->on('u.id', '=', 'tu.user_id')->where('tu.tenant_id', $tenantId);
            })
            ->leftJoin('user_roles as ur', function ($j) use ($tenantId) {
                $j->on('u.id', '=', 'ur.user_id')->where('ur.is_active', true)->where('ur.tenant_id', $tenantId);
            })
            ->leftJoin('roles as r', 'ur.role_id', '=', 'r.id')
            ->select('u.id', 'u.uuid', 'u.name', 'u.email', 'u.phone', DB::raw('COALESCE(r.slug, "employee") as role'), 'tu.joined_at', 'tu.status')
            ->orderBy('tu.joined_at', 'desc')
            ->limit(5)
            ->get() : [];

        $recentLogs = $tenantId ? DB::table('audit_logs as al')
            ->leftJoin('users as u', 'al.user_id', '=', 'u.id')
            ->where('al.tenant_id', $tenantId)
            ->select('al.id', 'al.action', 'al.ip_address', 'al.created_at', 'u.name as user_name')
            ->orderBy('al.created_at', 'desc')
            ->limit(10)
            ->get() : [];

        return response()->json([
            'status' => 'success',
            'role' => 'organization',
            'scope' => 'tenant',
            'data' => [
                'tenant' => [
                    'id'          => $tenant?->id,
                    'uuid'        => $tenant?->uuid,
                    'name'        => $tenant?->name,
                    'slug'        => $tenant?->slug,
                    'client_tier' => $tier,
                    'status'      => $tenant?->status ?? 'active',
                ],
                'quotas' => [
                    'users_current'   => $activeStaffCount,
                    'users_limit'     => $maxUsers,
                    'users_remaining' => max(0, $maxUsers - $activeStaffCount),
                    'outlets_current' => $outletsCount,
                    'outlets_limit'   => $maxOutlets,
                    'outlets_remaining'=> max(0, $maxOutlets - $outletsCount),
                ],
                'subscription' => $subscription,
                'recent_staff' => $recentStaff,
                'recent_activity' => $recentLogs,
            ],
        ]);
    }

    /**
     * Outlet Manager Dashboard: Store-scoped register, staff, and shift metrics.
     */
    protected function outletManagerDashboard($currentUser, $tenantId)
    {
        $outletUuid = $currentUser->outlet_uuid;
        $outlet = null;
        if ($outletUuid) {
            $outlet = DB::table('outlets')->where('uuid', $outletUuid)->first();
        } elseif ($currentUser->outlet_id) {
            $outlet = DB::table('outlets')->where('id', $currentUser->outlet_id)->first();
        }

        $outletStaffCount = 0;
        if ($outlet) {
            $outletStaffCount = DB::table('tenant_user_outlets as tuo')
                ->where('tuo.outlet_uuid', $outlet->uuid)
                ->where('tuo.is_active', true)
                ->count();
        }

        $activePinsCount = $tenantId ? DB::table('user_pos_pins')
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->count() : 0;

        return response()->json([
            'status' => 'success',
            'role' => $currentUser->role,
            'scope' => 'outlet',
            'data' => [
                'outlet' => $outlet ? [
                    'id'        => $outlet->id,
                    'uuid'      => $outlet->uuid,
                    'name'      => $outlet->name,
                    'code'      => $outlet->code ?? null,
                    'is_active' => (bool) $outlet->is_active,
                ] : null,
                'staff_count'     => $outletStaffCount,
                'active_pos_pins' => $activePinsCount,
            ],
        ]);
    }

    /**
     * Role-Scoped User Management Directory.
     * GET /api/v1/admin/users
     */
    public function users(Request $request)
    {
        $currentUser = $request->user();
        $callerRole = $currentUser?->role ?? 'user';
        $callerTenantId = $currentUser?->tenant_id;

        $query = DB::table('users as u')
            ->leftJoin('tenant_users as tu', 'u.id', '=', 'tu.user_id')
            ->leftJoin('tenants as t', 'tu.tenant_id', '=', 't.id')
            ->leftJoin('tenant_user_outlets as tuo', 'tu.id', '=', 'tuo.tenant_user_id')
            ->leftJoin('outlets as o', 'tuo.outlet_uuid', '=', 'o.uuid')
            ->leftJoin('user_roles as ur', function ($j) {
                $j->on('u.id', '=', 'ur.user_id')->where('ur.is_active', true);
            })
            ->leftJoin('roles as r', 'ur.role_id', '=', 'r.id');

        // Enforce Role Hierarchy Scoping
        if ($callerRole === 'super_admin') {
            // Super Admin can optionally filter by tenant_id
            if ($request->filled('tenant_id')) {
                $query->where('tu.tenant_id', $request->tenant_id);
            }
        } elseif (in_array($callerRole, ['organization', 'admin', 'administrator', 'owner'])) {
            // Organization Admin strictly limited to their own workspace
            $query->where('tu.tenant_id', $callerTenantId);
        } elseif (in_array($callerRole, ['outlet_manager', 'supervisor'])) {
            // Store Manager strictly limited to their own outlet
            $query->where('tu.tenant_id', $callerTenantId);
            if (!empty($currentUser->outlet_uuid)) {
                $query->where('tuo.outlet_uuid', $currentUser->outlet_uuid);
            }
        } else {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized.'], 403);
        }

        // Optional role filter
        if ($request->filled('role') && $request->role !== 'all') {
            $query->where('r.slug', $request->role);
        }

        // Search by name, email, or phone
        if ($request->filled('q')) {
            $q = trim($request->q);
            $query->where(function ($w) use ($q) {
                $w->where('u.name', 'like', "%{$q}%")
                  ->orWhere('u.email', 'like', "%{$q}%")
                  ->orWhere('u.phone', 'like', "%{$q}%");
            });
        }

        $users = $query->select(
            'u.id', 'u.uuid', 'u.name', 'u.email', 'u.phone', 'u.avatar_url', 'u.is_active',
            'tu.tenant_id', 't.name as tenant_name',
            DB::raw('COALESCE(r.slug, "user") as role'),
            DB::raw('r.name as role_name'),
            DB::raw('COALESCE(o.id, NULL) as outlet_id'),
            DB::raw('COALESCE(tuo.outlet_uuid, NULL) as outlet_uuid'),
            'o.name as outlet_name',
            'u.created_at', 'u.updated_at'
        )->distinct()->orderBy('u.created_at', 'desc')->get();

        return response()->json([
            'status' => 'success',
            'caller_role' => $callerRole,
            'count' => $users->count(),
            'data' => UserResource::collection($users),
        ]);
    }

    /**
     * Role-Scoped Available Roles Listing.
     * GET /api/v1/admin/roles
     */
    public function roles(Request $request)
    {
        $currentUser = $request->user();
        $callerRole = $currentUser?->role ?? 'user';
        $callerTenantId = $currentUser?->tenant_id;

        $query = DB::table('roles');

        if ($callerRole === 'super_admin') {
            // Super Admin can view and assign all system and tenant roles
            $roles = $query->orderBy('is_system', 'desc')->orderBy('name', 'asc')->get();
        } elseif (in_array($callerRole, ['organization', 'admin', 'administrator', 'owner'])) {
            // Organization Admin can assign staff roles within their tenant, excluding super_admin
            $query->where('slug', '!=', 'super_admin');
            if ($callerTenantId) {
                $query->where(function ($q) use ($callerTenantId) {
                    $q->whereNull('company_id')->orWhere('company_id', $callerTenantId);
                });
            }
            $roles = $query->orderBy('name', 'asc')->get();
        } elseif (in_array($callerRole, ['outlet_manager', 'supervisor'])) {
            // Store Manager can only assign store staff roles
            $allowedStaff = ['cashier', 'supervisor', 'employee', 'user'];
            $roles = $query->whereIn('slug', $allowedStaff)->orderBy('name', 'asc')->get();
        } else {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized.'], 403);
        }

        return response()->json([
            'status' => 'success',
            'caller_role' => $callerRole,
            'data' => $roles,
        ]);
    }

    /**
     * Role-Scoped Tenant Listing.
     * GET /api/v1/admin/tenants
     */
    public function tenants(Request $request)
    {
        $currentUser = $request->user();
        $callerRole = $currentUser?->role ?? 'user';

        if ($callerRole === 'super_admin') {
            $query = DB::table('tenants')->orderBy('created_at', 'desc');
            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }
            if ($request->filled('client_tier')) {
                $query->where('client_tier', $request->client_tier);
            }
            $tenants = $query->get();

            return response()->json([
                'status' => 'success',
                'count' => $tenants->count(),
                'data' => $tenants,
            ]);
        }

        if (in_array($callerRole, ['organization', 'admin', 'administrator', 'owner'])) {
            $tenant = DB::table('tenants')->where('id', $currentUser->tenant_id)->first();
            return response()->json([
                'status' => 'success',
                'data' => $tenant ? [$tenant] : [],
            ]);
        }

        return response()->json(['status' => 'error', 'message' => 'Forbidden.'], 403);
    }

    /**
     * Role-Scoped Audit Logs.
     * GET /api/v1/admin/audit-logs
     */
    public function auditLogs(Request $request)
    {
        $currentUser = $request->user();
        $callerRole = $currentUser?->role ?? 'user';
        $callerTenantId = $currentUser?->tenant_id;

        $query = DB::table('audit_logs as al')
            ->leftJoin('users as u', 'al.user_id', '=', 'u.id')
            ->leftJoin('tenants as t', 'al.tenant_id', '=', 't.id');

        if ($callerRole === 'super_admin') {
            if ($request->filled('tenant_id')) {
                $query->where('al.tenant_id', $request->tenant_id);
            }
        } elseif (in_array($callerRole, ['organization', 'admin', 'administrator', 'owner'])) {
            $query->where('al.tenant_id', $callerTenantId);
        } elseif (in_array($callerRole, ['outlet_manager', 'supervisor'])) {
            $query->where('al.tenant_id', $callerTenantId)->where('al.user_id', $currentUser->id);
        } else {
            return response()->json(['status' => 'error', 'message' => 'Forbidden.'], 403);
        }

        $logs = $query->select(
            'al.id', 'al.uuid', 'al.tenant_id', 't.name as tenant_name',
            'al.user_id', 'u.name as user_name', 'u.email as user_email',
            'al.action', 'al.module', 'al.ip_address', 'al.created_at'
        )->orderBy('al.created_at', 'desc')->limit(50)->get();

        return response()->json([
            'status' => 'success',
            'caller_role' => $callerRole,
            'count' => $logs->count(),
            'data' => $logs,
        ]);
    }
}

