<?php

namespace App\Policies;

use App\Models\Outlet;
use App\Models\User;
use App\Services\UserAuthorizationService;

class OutletPolicy
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
     * Determine whether the user can list outlets in their tenant.
     */
    public function viewAny(User $user): bool
    {
        return ! empty($user->tenant_id);
    }

    /**
     * Determine whether the user can view an outlet.
     */
    public function view(User $user, Outlet $outlet): bool
    {
        return ! empty($user->tenant_id) && (int) $user->tenant_id === (int) $outlet->tenant_id;
    }

    /**
     * Determine whether the user can create outlets.
     */
    public function create(User $user): bool
    {
        return UserAuthorizationService::isTenantAdmin($user);
    }

    /**
     * Determine whether the user can update an outlet.
     */
    public function update(User $user, Outlet $outlet): bool
    {
        if (empty($user->tenant_id) || (int) $user->tenant_id !== (int) $outlet->tenant_id) {
            return false;
        }

        if (UserAuthorizationService::isTenantAdmin($user)) {
            return true;
        }

        // Outlet manager assigned to this specific outlet can update operational info
        if (UserAuthorizationService::isOutletManager($user)) {
            return ! empty($user->outlet_uuid) && $user->outlet_uuid === $outlet->uuid;
        }

        return false;
    }

    /**
     * Determine whether the user can delete an outlet.
     */
    public function delete(User $user, Outlet $outlet): bool
    {
        if (empty($user->tenant_id) || (int) $user->tenant_id !== (int) $outlet->tenant_id) {
            return false;
        }

        return UserAuthorizationService::isTenantAdmin($user);
    }
}

