<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class UserAuthorizationService
{
    /**
     * Check if the user is a platform super administrator.
     */
    public static function isSuperAdmin($user): bool
    {
        if (!$user) return false;
        return ($user->role ?? null) === 'super_admin';
    }

    /**
     * Check if the user is a tenant administrator (organization owner or admin).
     */
    public static function isTenantAdmin($user, ?int $tenantId = null): bool
    {
        if (!$user) return false;
        if (self::isSuperAdmin($user)) return true;

        $userTenantId = $user->tenant_id ?? null;
        if ($tenantId !== null && $userTenantId && (int) $userTenantId !== (int) $tenantId) {
            return false;
        }

        $adminRoles = ['organization', 'admin', 'administrator', 'owner', 'tenant_admin'];
        if (in_array($user->role ?? '', $adminRoles, true)) {
            return true;
        }

        if (!empty($user->id)) {
            $isOwner = DB::table('tenants')->where('owner_user_id', $user->id);
            if ($tenantId !== null) {
                $isOwner->where('id', $tenantId);
            }
            if ($isOwner->exists()) {
                return true;
            }

            $hasAdminRole = DB::table('user_roles as ur')
                ->join('roles as r', 'ur.role_id', '=', 'r.id')
                ->where('ur.user_id', $user->id)
                ->where('ur.is_active', true)
                ->whereIn('r.slug', $adminRoles);

            if ($tenantId !== null) {
                $hasAdminRole->where('ur.tenant_id', $tenantId);
            }

            return $hasAdminRole->exists();
        }

        return false;
    }

    /**
     * Check if the user is an outlet manager.
     */
    public static function isOutletManager($user): bool
    {
        if (!$user) return false;
        $role = $user->role ?? '';
        return in_array($role, ['outlet_manager', 'supervisor', 'store_manager'], true);
    }

    /**
     * Determine if caller can invite a user with the requested role into an outlet/tenant.
     *
     * @return array{0: bool, 1: string, 2: int} [allowed, message, statusCode]
     */
    public static function authorizeInvitation($currentUser, string $targetRoleSlug, ?string $targetOutletUuid, ?int $tenantId): array
    {
        if (!$currentUser) {
            return [false, 'Unauthenticated.', 401];
        }

        // 1. Super Admin can invite anyone anywhere
        if (self::isSuperAdmin($currentUser)) {
            return [true, '', 200];
        }

        // Non-super-admins cannot invite super_admin
        if ($targetRoleSlug === 'super_admin') {
            return [false, 'Only a super administrator may invite users with the super_admin role.', 403];
        }

        // Must belong to the invited tenant
        if ($tenantId && $currentUser->tenant_id && (int) $currentUser->tenant_id !== (int) $tenantId) {
            return [false, 'Unauthorized access to the target workspace.', 403];
        }

        // 2. Tenant Administrator can invite any role within tenant
        if (self::isTenantAdmin($currentUser, $tenantId)) {
            return [true, '', 200];
        }

        // 3. Outlet Manager rules:
        if (self::isOutletManager($currentUser)) {
            $adminRoles = ['super_admin', 'organization', 'admin', 'administrator', 'owner', 'tenant_admin'];
            if (in_array($targetRoleSlug, $adminRoles, true)) {
                return [false, 'Outlet managers are not authorized to invite or create tenant administrators.', 403];
            }

            if ($targetRoleSlug === 'outlet_manager') {
                return [false, 'Outlet managers are not authorized to create other outlet managers.', 403];
            }

            // Outlet scope verification: Outlet manager can ONLY invite users to their own outlet
            $callerOutletUuid = $currentUser->outlet_uuid ?? null;
            if (!$callerOutletUuid) {
                return [false, 'Caller does not have an assigned outlet scope.', 403];
            }

            if ($targetOutletUuid && $targetOutletUuid !== $callerOutletUuid) {
                return [false, 'Outlet managers can only invite staff to their assigned outlet.', 403];
            }

            return [true, '', 200];
        }

        // All other subordinate roles (cashier, inventory_clerk, employee) cannot invite anyone
        return [false, 'Unauthorized. Administrative or manager privileges required to invite users.', 403];
    }

    /**
     * Determine if caller can manage (update/edit) the target user.
     *
     * @return array{0: bool, 1: string, 2: int}
     */
    public static function authorizeUserManagement($currentUser, $targetUser, ?string $newRoleSlug = null, ?string $newOutletUuid = null): array
    {
        if (!$currentUser) {
            return [false, 'Unauthenticated.', 401];
        }

        if (self::isSuperAdmin($currentUser)) {
            return [true, '', 200];
        }

        // Cannot manage super admins
        $targetRole = is_object($targetUser) ? ($targetUser->role ?? '') : '';
        if ($targetRole === 'super_admin') {
            return [false, 'Unauthorized access to super administrator accounts.', 403];
        }

        // Must be in the same tenant
        $targetTenantId = is_object($targetUser) ? ($targetUser->tenant_id ?? null) : null;
        if ($targetTenantId && $currentUser->tenant_id && (int) $targetTenantId !== (int) $currentUser->tenant_id) {
            return [false, 'Unauthorized access to user from another workspace.', 403];
        }

        // Self-management: user can update own details, but cannot change own role or suspension
        if ((int) $currentUser->id === (int) $targetUser->id) {
            if ($newRoleSlug && $newRoleSlug !== $targetRole) {
                return [false, 'Users cannot modify their own administrative role.', 403];
            }
            return [true, '', 200];
        }

        // Tenant Admin can manage any non-super-admin user within their tenant
        if (self::isTenantAdmin($currentUser, $targetTenantId)) {
            if ($newRoleSlug === 'super_admin') {
                return [false, 'Cannot assign super_admin role.', 403];
            }
            return [true, '', 200];
        }

        // Outlet Manager rules:
        if (self::isOutletManager($currentUser)) {
            // Cannot manage tenant admins or fellow managers
            $adminRoles = ['super_admin', 'organization', 'admin', 'administrator', 'owner', 'tenant_admin', 'outlet_manager'];
            if (in_array($targetRole, $adminRoles, true)) {
                return [false, 'Outlet managers cannot modify tenant administrators or fellow managers.', 403];
            }

            // Cannot elevate anyone to admin or manager
            if ($newRoleSlug && in_array($newRoleSlug, $adminRoles, true)) {
                return [false, 'Outlet managers cannot grant administrator or manager roles.', 403];
            }

            // Must match outlet scope
            $callerOutlet = $currentUser->outlet_uuid ?? null;
            $targetOutlet = $targetUser->outlet_uuid ?? null;

            if ($callerOutlet && $targetOutlet && $callerOutlet !== $targetOutlet) {
                return [false, 'Outlet managers can only manage staff in their assigned outlet.', 403];
            }

            if ($newOutletUuid && $callerOutlet && $newOutletUuid !== $callerOutlet) {
                return [false, 'Outlet managers cannot reassign staff to other outlets.', 403];
            }

            return [true, '', 200];
        }

        return [false, 'Unauthorized access to manage user accounts.', 403];
    }

    /**
     * Determine if caller can reset target user's password.
     *
     * @return array{0: bool, 1: string, 2: int}
     */
    public static function authorizePasswordReset($currentUser, $targetUser): array
    {
        if (!$currentUser) {
            return [false, 'Unauthenticated.', 401];
        }

        if (self::isSuperAdmin($currentUser)) {
            return [true, '', 200];
        }

        $targetRole = is_object($targetUser) ? ($targetUser->role ?? '') : '';
        if ($targetRole === 'super_admin') {
            return [false, 'Unauthorized access to super administrator accounts.', 403];
        }

        $targetTenantId = is_object($targetUser) ? ($targetUser->tenant_id ?? null) : null;
        if ($targetTenantId && $currentUser->tenant_id && (int) $targetTenantId !== (int) $currentUser->tenant_id) {
            return [false, 'Unauthorized access to user from another workspace.', 403];
        }

        // Tenant Admin can reset password of users within their tenant
        if (self::isTenantAdmin($currentUser, $targetTenantId)) {
            return [true, '', 200];
        }

        // Outlet Manager rules:
        if (self::isOutletManager($currentUser)) {
            $adminRoles = ['super_admin', 'organization', 'admin', 'administrator', 'owner', 'tenant_admin', 'outlet_manager'];
            if (in_array($targetRole, $adminRoles, true)) {
                return [false, 'Outlet managers cannot reset passwords of tenant administrators or managers.', 403];
            }

            $callerOutlet = $currentUser->outlet_uuid ?? null;
            $targetOutlet = $targetUser->outlet_uuid ?? null;
            if ($callerOutlet && $targetOutlet && $callerOutlet !== $targetOutlet) {
                return [false, 'Outlet managers can only reset passwords for staff in their assigned outlet.', 403];
            }

            return [true, '', 200];
        }

        return [false, 'Unauthorized to reset user passwords.', 403];
    }
}
