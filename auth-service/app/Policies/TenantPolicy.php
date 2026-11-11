<?php

namespace App\Policies;

use App\Models\Tenant;
use App\Models\User;
use App\Services\UserAuthorizationService;

class TenantPolicy
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
     * Determine whether the user can list all tenants (Super Admin only).
     */
    public function viewAny(User $user): bool
    {
        return UserAuthorizationService::isSuperAdmin($user);
    }

    /**
     * Determine whether the user can view tenant details.
     */
    public function view(User $user, Tenant $tenant): bool
    {
        return ! empty($user->tenant_id) && (int) $user->tenant_id === (int) $tenant->id;
    }

    /**
     * Determine whether the user can update tenant details.
     */
    public function update(User $user, Tenant $tenant): bool
    {
        return ! empty($user->tenant_id)
            && (int) $user->tenant_id === (int) $tenant->id
            && UserAuthorizationService::isTenantAdmin($user, (int) $tenant->id);
    }

    /**
     * Determine whether the user can manage tenant settings.
     */
    public function manageSettings(User $user, Tenant $tenant): bool
    {
        return ! empty($user->tenant_id)
            && (int) $user->tenant_id === (int) $tenant->id
            && UserAuthorizationService::isTenantAdmin($user, (int) $tenant->id);
    }

    /**
     * Determine whether the user can manage platform modules for a tenant.
     */
    public function manageModules(User $user, Tenant $tenant): bool
    {
        return UserAuthorizationService::isSuperAdmin($user);
    }

    /**
     * Determine whether the user can manage subscriptions for a tenant.
     */
    public function manageSubscriptions(User $user, Tenant $tenant): bool
    {
        return ! empty($user->tenant_id)
            && (int) $user->tenant_id === (int) $tenant->id
            && UserAuthorizationService::isTenantAdmin($user, (int) $tenant->id);
    }

    /**
     * Determine whether the user can suspend or reactivate a tenant workspace.
     */
    public function suspend(User $user, Tenant $tenant): bool
    {
        return UserAuthorizationService::isSuperAdmin($user);
    }

    /**
     * Determine whether the user can delete a tenant workspace.
     */
    public function delete(User $user, Tenant $tenant): bool
    {
        return UserAuthorizationService::isSuperAdmin($user);
    }
}

