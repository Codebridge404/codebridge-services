<?php

namespace App\Policies;

use App\Models\User;
use App\Services\UserAuthorizationService;
use App\Support\SchemaGuard;
use Illuminate\Support\Facades\DB;

class UserPolicy
{
    /**
     * Verify both users belong to the caller's active workspace without allowing null === null matches.
     */
    protected function sameWorkspace(User $user, User $target): bool
    {
        if (empty($user->tenant_id)) {
            return false;
        }

        if ((int) $user->tenant_id === (int) $target->tenant_id) {
            return true;
        }

        if (SchemaGuard::hasTable('tenant_users')) {
            return DB::table('tenant_users')
                ->where('tenant_id', (int) $user->tenant_id)
                ->where('user_id', $target->id)
                ->exists();
        }

        return false;
    }

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
     * Determine whether the user can view the user list.
     */
    public function viewAny(User $user): bool
    {
        return UserAuthorizationService::isTenantAdmin($user)
            || UserAuthorizationService::isOutletManager($user);
    }

    /**
     * Determine whether the user can view a specific user.
     */
    public function view(User $user, User $target): bool
    {
        if ($user->id === $target->id) {
            return true;
        }

        return $this->sameWorkspace($user, $target);
    }

    /**
     * Determine whether the user can create staff accounts.
     */
    public function create(User $user): bool
    {
        return UserAuthorizationService::isTenantAdmin($user)
            || UserAuthorizationService::isOutletManager($user);
    }

    /**
     * Determine whether the user can update a staff account.
     */
    public function update(User $user, User $target): bool
    {
        // Non-super-admins cannot update platform super admins
        if (UserAuthorizationService::hasPlatformSuperAdminRole($target)) {
            return false;
        }

        // Must belong to the same tenant workspace
        if (! $this->sameWorkspace($user, $target)) {
            return false;
        }

        // Self-update is allowed for basic profile fields
        if ($user->id === $target->id) {
            return true;
        }

        // Tenant admins can update any member in their workspace
        if (UserAuthorizationService::isTenantAdmin($user)) {
            return true;
        }

        // Outlet managers can only update non-admin staff in their assigned outlet
        if (UserAuthorizationService::isOutletManager($user)) {
            return ! in_array($target->role, array_merge(['super_admin'], UserAuthorizationService::ADMIN_ROLE_SLUGS), true);
        }

        return false;
    }

    /**
     * Determine whether the user can delete a staff account.
     */
    public function delete(User $user, User $target): bool
    {
        // Cannot delete yourself
        if ($user->id === $target->id) {
            return false;
        }

        // Cannot delete workspace owner
        if ($target->role === 'owner') {
            return false;
        }

        // Non-super-admins cannot delete platform super admins
        if (UserAuthorizationService::hasPlatformSuperAdminRole($target)) {
            return false;
        }

        // Must belong to the same tenant workspace
        if (! $this->sameWorkspace($user, $target)) {
            return false;
        }

        return UserAuthorizationService::isTenantAdmin($user);
    }

    /**
     * Determine whether the user can invite or provision a role.
     */
    public function invite(User $user, string $roleSlug, ?string $outletUuid = null, ?int $tenantId = null): bool
    {
        [$authorized] = UserAuthorizationService::authorizeInvitation($user, $roleSlug, $outletUuid, $tenantId);

        return $authorized;
    }

    /**
     * Determine whether the user can reset a user's password.
     */
    public function resetPassword(User $user, User $target): bool
    {
        if ($user->id === $target->id) {
            return true;
        }

        [$canReset] = UserAuthorizationService::authorizePasswordReset($user, $target);

        return $canReset;
    }
}

