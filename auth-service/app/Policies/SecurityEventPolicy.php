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
     * Determine whether the user can manage global threat defense settings.
     * Restricted strictly to Platform Super Admins.
     */
    public function manageSettings(User $user): bool
    {
        return UserAuthorizationService::hasPlatformSuperAdminRole($user);
    }

    /**
     * Determine whether the user can list globally banned IPs.
     * Restricted strictly to Platform Super Admins.
     */
    public function viewBannedIps(User $user): bool
    {
        return UserAuthorizationService::hasPlatformSuperAdminRole($user);
    }

    /**
     * Determine whether the user can globally ban an IP.
     * Restricted strictly to Platform Super Admins (global cache scope).
     */
    public function banIp(User $user): bool
    {
        return UserAuthorizationService::hasPlatformSuperAdminRole($user);
    }

    /**
     * Determine whether the user can globally unban an IP.
     * Restricted strictly to Platform Super Admins (global cache scope).
     */
    public function unbanIp(User $user): bool
    {
        return UserAuthorizationService::hasPlatformSuperAdminRole($user);
    }
}

