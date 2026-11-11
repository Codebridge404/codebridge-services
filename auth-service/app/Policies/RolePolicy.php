<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;
use App\Services\UserAuthorizationService;

class RolePolicy
{
    /**
     * Platform Super Admin bypasses all checks.
     */
    public function before(User $user, string $ability): ?bool
    {
        if (UserAuthorizationService::hasPlatformSuperAdminRole($user)) {
            return true;
        }

        return null;
    }

    /**
     * Determine whether the user can view roles.
     */
    public function viewAny(User $user): bool
    {
        return ! empty($user->tenant_id) || UserAuthorizationService::isSuperAdmin($user);
    }

    /**
     * Determine whether the user can view a specific role.
     */
    public function view(User $user, Role $role): bool
    {
        if ($role->tenant_id === null && $role->company_id === null) {
            return ! empty($user->tenant_id) || UserAuthorizationService::isSuperAdmin($user);
        }

        if (empty($user->tenant_id)) {
            return false;
        }

        $roleTenant = $role->tenant_id ?? $role->company_id;

        return (int) $user->tenant_id === (int) $roleTenant;
    }

    /**
     * Determine whether the user can create custom roles.
     */
    public function create(User $user): bool
    {
        return UserAuthorizationService::isTenantAdmin($user);
    }

    /**
     * Determine whether the user can update a role.
     */
    public function update(User $user, Role $role): bool
    {
        // System roles (tenant_id is null) can only be updated by Platform Super Admin (handled by before())
        if ($role->tenant_id === null && $role->company_id === null) {
            return false;
        }

        if (empty($user->tenant_id)) {
            return false;
        }

        $roleTenant = $role->tenant_id ?? $role->company_id;

        return (int) $user->tenant_id === (int) $roleTenant
            && UserAuthorizationService::isTenantAdmin($user);
    }

    /**
     * Determine whether the user can delete a role.
     */
    public function delete(User $user, Role $role): bool
    {
        // System roles cannot be deleted by tenant administrators
        if ($role->tenant_id === null && $role->company_id === null) {
            return false;
        }

        if (empty($user->tenant_id)) {
            return false;
        }

        $roleTenant = $role->tenant_id ?? $role->company_id;

        return (int) $user->tenant_id === (int) $roleTenant
            && UserAuthorizationService::isTenantAdmin($user);
    }
}

