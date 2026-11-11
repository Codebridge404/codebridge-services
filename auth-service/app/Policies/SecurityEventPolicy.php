<?php

namespace App\Policies;

use App\Models\SecurityEvent;
use App\Models\User;
use App\Services\UserAuthorizationService;

class SecurityEventPolicy
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
     * Determine whether the user can list security events.
     */
    public function viewAny(User $user): bool
    {
        return UserAuthorizationService::isTenantAdmin($user)
            || UserAuthorizationService::isSuperAdmin($user);
    }

    /**
     * Determine whether the user can view an individual security event.
     */
    public function view(User $user, SecurityEvent $event): bool
    {
        if (UserAuthorizationService::isSuperAdmin($user)) {
            return true;
        }

        if (! empty($user->tenant_id)) {
            return (int) $user->tenant_id === (int) $event->tenant_id;
        }

        return (int) $user->id === (int) $event->user_id;
    }

    /**
     * Determine whether the user can manage threat defense settings.
     */
    public function manageSettings(User $user): bool
    {
        return UserAuthorizationService::isSuperAdmin($user);
    }

    /**
     * Determine whether the user can ban an IP.
     */
    public function banIp(User $user): bool
    {
        return UserAuthorizationService::isTenantAdmin($user)
            || UserAuthorizationService::isSuperAdmin($user);
    }

    /**
     * Determine whether the user can unban an IP.
     */
    public function unbanIp(User $user): bool
    {
        return UserAuthorizationService::isTenantAdmin($user)
            || UserAuthorizationService::isSuperAdmin($user);
    }
}

