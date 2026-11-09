<?php

/**
 * Author: Antigravity Team
 * Date: 2026-11-05
 * Task: BD-0138
 */

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\User\UserResource;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\UserAuthorizationService;
use App\Support\SchemaGuard;
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
    protected function resolveCallerContext(Request $request): ?array
    {
        $currentUser = $request->user();
        if (!$currentUser) {
            return null;
        }

        $claimTenantId = $request->attributes->get('jwt_tenant_id');
        $context = UserAuthorizationService::resolveAuthorizationContext(
            $currentUser,
            null,
            is_numeric($claimTenantId) ? (int) $claimTenantId : null,
            false
        );

        return !empty($context['authorized']) ? $context : null;
    }

    /**
     * Unified Role-Based Admin Dashboard Metrics.
     * GET /api/v1/admin/dashboard
     */
    public function dashboard(Request $request)
    {
        $currentUser = $request->user();
        $context = $this->resolveCallerContext($request);
        if (!$context) {
            return response()->json([
                'status' => 'error',
                'message' => 'Forbidden. Your role does not have administrative dashboard privileges.',
            ], 403);
        }

        $role = $context['role'] ?? 'user';
        $tenantId = $context['tenant_id'] ?? $currentUser?->tenant_id;

        if (!empty($context['is_platform_super_admin'])) {
            return $this->superAdminDashboard();
        }

        if (!empty($context['is_super_admin']) || in_array($role, UserAuthorizationService::ADMIN_ROLE_SLUGS, true)) {
            return $this->organizationAdminDashboard($currentUser, $tenantId);
        }

        if (in_array($role, ['outlet_manager', 'supervisor', 'store_manager'], true)) {
            return $this->outletManagerDashboard($currentUser, $tenantId, $context);
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
            ->select('u.id', 'u.uuid', 'u.name', 'u.email', 'u.phone', DB::raw("COALESCE(r.slug, 'employee') as role"), 'tu.joined_at', 'tu.status')
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
    protected function outletManagerDashboard($currentUser, $tenantId, ?array $context = null)
    {
        $outletUuid = $context['outlet_uuid'] ?? $currentUser->outlet_uuid;
        $outletId = $context['outlet_id'] ?? $currentUser->outlet_id;
        $outlet = null;
        if ($outletUuid) {
            $outletQuery = DB::table('outlets')->where('uuid', $outletUuid);
            if ($tenantId) {
                $outletQuery->where('tenant_id', $tenantId);
            }
            $outlet = $outletQuery->first();
        } elseif ($outletId) {
            $outletQuery = DB::table('outlets')->where('id', $outletId);
            if ($tenantId) {
                $outletQuery->where('tenant_id', $tenantId);
            }
            $outlet = $outletQuery->first();
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
            'role' => $context['role'] ?? $currentUser->role,
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
        $context = $this->resolveCallerContext($request);
        if (!$context) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized.'], 403);
        }

        $callerRole = $context['role'] ?? 'user';
        $callerTenantId = $context['tenant_id'] ?? $currentUser?->tenant_id;
        $isPlatformSuperAdmin = !empty($context['is_platform_super_admin']);

        $query = DB::table('users as u')
            ->leftJoin('tenant_users as tu', 'u.id', '=', 'tu.user_id')
            ->leftJoin('tenants as t', 'tu.tenant_id', '=', 't.id')
            ->leftJoin('tenant_user_outlets as tuo', 'tu.id', '=', 'tuo.tenant_user_id')
            ->leftJoin('outlets as o', 'tuo.outlet_uuid', '=', 'o.uuid')
            ->leftJoin('user_roles as ur', function ($j) {
                $j->on('u.id', '=', 'ur.user_id')
                    ->where('ur.is_active', true)
                    ->where(function ($q) {
                        $q->whereColumn('ur.tenant_id', 'tu.tenant_id')
                            ->orWhereNull('ur.tenant_id');
                    });
            })
            ->leftJoin('roles as r', 'ur.role_id', '=', 'r.id');

        // Enforce Role Hierarchy Scoping
        if ($isPlatformSuperAdmin) {
            // Super Admin can optionally filter by tenant_id
            if ($request->filled('tenant_id')) {
                $query->where('tu.tenant_id', $request->tenant_id);
            }
        } elseif (!empty($context['is_super_admin']) || in_array($callerRole, UserAuthorizationService::ADMIN_ROLE_SLUGS, true)) {
            // Organization Admin strictly limited to their own workspace
            $query->where('tu.tenant_id', $callerTenantId);
        } elseif (in_array($callerRole, ['outlet_manager', 'supervisor', 'store_manager'], true)) {
            // Store Manager strictly limited to their own outlet
            $query->where('tu.tenant_id', $callerTenantId);
            $callerOutletUuid = $context['outlet_uuid'] ?? $currentUser?->outlet_uuid;
            if (!empty($callerOutletUuid)) {
                $query->where('tuo.outlet_uuid', $callerOutletUuid);
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
            $q = SchemaGuard::escapeLike(trim((string) $request->q));
            $query->where(function ($w) use ($q) {
                $w->where('u.name', 'like', "%{$q}%")
                  ->orWhere('u.email', 'like', "%{$q}%")
                  ->orWhere('u.phone', 'like', "%{$q}%");
            });
        }

        $perPage = min(100, max(1, (int) $request->query('per_page', 50)));
        $idPaginator = (clone $query)
            ->select('u.id')
            ->groupBy(['u.id', 'u.created_at'])
            ->orderBy('u.created_at', 'desc')
            ->paginate($perPage);

        $pageIds = collect($idPaginator->items())->pluck('id')->all();
        $users = empty($pageIds)
            ? collect()
            : $query->whereIn('u.id', $pageIds)
                ->select(
                    'u.id', 'u.uuid', 'u.name', 'u.email', 'u.phone', 'u.avatar_url', 'u.is_active',
                    'tu.tenant_id', 't.name as tenant_name',
                    DB::raw("COALESCE(r.slug, 'user') as role"),
                    DB::raw('r.name as role_name'),
                    DB::raw('COALESCE(o.id, NULL) as outlet_id'),
                    DB::raw('COALESCE(tuo.outlet_uuid, NULL) as outlet_uuid'),
                    'o.name as outlet_name',
                    'u.created_at', 'u.updated_at'
                )->distinct()->orderBy('u.created_at', 'desc')->get()->unique('id')->values();

        return response()->json([
            'status' => 'success',
            'caller_role' => $callerRole,
            'count' => $users->count(),
            'data' => UserResource::collection($users),
            'pagination' => [
                'current_page' => $idPaginator->currentPage(),
                'last_page' => $idPaginator->lastPage(),
                'per_page' => $idPaginator->perPage(),
                'total' => $idPaginator->total(),
            ],
        ]);
    }

    /**
     * Role-Scoped Available Roles Listing.
     * GET /api/v1/admin/roles
     */
    public function roles(Request $request)
    {
        $currentUser = $request->user();
        $context = $this->resolveCallerContext($request);
        if (!$context) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized.'], 403);
        }

        $callerRole = $context['role'] ?? 'user';
        $callerTenantId = $context['tenant_id'] ?? $currentUser?->tenant_id;
        $isPlatformSuperAdmin = !empty($context['is_platform_super_admin']);

        $query = DB::table('roles');

        if ($isPlatformSuperAdmin) {
            // Super Admin can view and assign all system and tenant roles
            $roles = $query->orderBy('is_system', 'desc')->orderBy('name', 'asc')->get();
        } elseif (!empty($context['is_super_admin']) || in_array($callerRole, UserAuthorizationService::ADMIN_ROLE_SLUGS, true)) {
            // Organization Admin can assign staff roles within their tenant, excluding super_admin
            $query->where('slug', '!=', 'super_admin');
            if ($callerTenantId) {
                $query->where(function ($q) use ($callerTenantId) {
                    $q->where(function ($sysQ) {
                        $sysQ->whereNull('tenant_id')->whereNull('company_id');
                    })->orWhere('tenant_id', $callerTenantId)
                      ->orWhere('company_id', $callerTenantId);
                });
            }
            $roles = $query->orderBy('name', 'asc')->get();
        } elseif (in_array($callerRole, ['outlet_manager', 'supervisor', 'store_manager'], true)) {
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
        $context = $this->resolveCallerContext($request);
        if (!$context) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden.'], 403);
        }

        $callerRole = $context['role'] ?? 'user';
        $callerTenantId = $context['tenant_id'] ?? $currentUser?->tenant_id;
        $isPlatformSuperAdmin = !empty($context['is_platform_super_admin']);

        if ($isPlatformSuperAdmin) {
            $query = DB::table('tenants')->orderBy('created_at', 'desc');
            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }
            if ($request->filled('client_tier')) {
                $query->where('client_tier', $request->client_tier);
            }
            $perPage = min(100, max(1, (int) $request->query('per_page', 50)));
            $paginator = $query->paginate($perPage);

            return response()->json([
                'status' => 'success',
                'count' => count($paginator->items()),
                'data' => $paginator->items(),
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                ],
            ]);
        }

        if (!empty($context['is_super_admin']) || in_array($callerRole, UserAuthorizationService::ADMIN_ROLE_SLUGS, true)) {
            $tenant = $callerTenantId ? DB::table('tenants')->where('id', $callerTenantId)->first() : null;
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
        $context = $this->resolveCallerContext($request);
        if (!$context) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden.'], 403);
        }

        $callerRole = $context['role'] ?? 'user';
        $callerTenantId = $context['tenant_id'] ?? $currentUser?->tenant_id;
        $isPlatformSuperAdmin = !empty($context['is_platform_super_admin']);

        $query = DB::table('audit_logs as al')
            ->leftJoin('users as u', 'al.user_id', '=', 'u.id')
            ->leftJoin('tenants as t', 'al.tenant_id', '=', 't.id');

        if ($isPlatformSuperAdmin) {
            if ($request->filled('tenant_id')) {
                $query->where('al.tenant_id', $request->tenant_id);
            }
        } elseif (!empty($context['is_super_admin']) || in_array($callerRole, UserAuthorizationService::ADMIN_ROLE_SLUGS, true)) {
            $query->where('al.tenant_id', $callerTenantId);
        } elseif (in_array($callerRole, ['outlet_manager', 'supervisor', 'store_manager'], true)) {
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

