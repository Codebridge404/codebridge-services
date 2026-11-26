<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Cross-Service Module Entitlement & Global Kill-Switch Service (Operations Service).
 *
 * Evaluates platform-wide module kill-switches and per-tenant subscriptions using:
 * 1. The Auth-owned GET /api/v1/auth/authorize-context payload (enabled_modules / disabled_modules).
 * 2. The shared Redis cache (platform:modules:global & platform:modules:tenant:{id}).
 */
class ModuleEntitlementService
{
    public const GLOBAL_CACHE_KEY = 'platform:modules:global';

    public const TENANT_CACHE_PREFIX = 'platform:modules:tenant:';

    /**
     * Canonical aliases between short route module names and platform_modules IDs.
     *
     * @var array<string, array<int, string>>
     */
    protected const ALIASES = [
        'pos' => ['pos', 'pos-management'],
        'pos-management' => ['pos', 'pos-management'],
        'inventory' => ['inventory', 'inventory-suite'],
        'inventory-suite' => ['inventory', 'inventory-suite'],
        'finance' => ['finance', 'financial-management'],
        'financial-management' => ['finance', 'financial-management'],
        'crm' => ['crm', 'crm-loyalty'],
        'crm-loyalty' => ['crm', 'crm-loyalty'],
        'hrm' => ['hrm', 'staff-hrm'],
        'staff-hrm' => ['hrm', 'staff-hrm'],
        'meetings' => ['meetings', 'meeting-management'],
        'meeting-management' => ['meetings', 'meeting-management'],
        'shop' => ['shop', 'shop-storefront'],
        'shop-storefront' => ['shop', 'shop-storefront'],
    ];

    /**
     * Evaluate whether the current request/tenant may access the given module.
     *
     * @return array{allowed: bool, status?: int, code?: string, message?: string}
     */
    public function checkAccess(string $moduleId, Request $request, ?User $user = null): array
    {
        $normalized = strtolower(trim($moduleId));
        $candidates = self::ALIASES[$normalized] ?? [$normalized];
        $authContext = $request->attributes->get('auth_context');
        $authContext = is_array($authContext) ? $authContext : [];

        $globalModules = $this->getGlobalModules();

        // 1. Global kill-switch via Auth authorize-context or shared Redis cache -> 503 MODULE_DISABLED
        $disabledModules = is_array($authContext['disabled_modules'] ?? null)
            ? array_map(fn ($id) => strtolower((string) $id), $authContext['disabled_modules'])
            : [];

        foreach ($candidates as $candidate) {
            if (in_array($candidate, $disabledModules, true)) {
                return [
                    'allowed' => false,
                    'status' => 503,
                    'code' => 'MODULE_DISABLED',
                    'message' => "Module '{$normalized}' is temporarily unavailable due to a platform kill-switch.",
                ];
            }

            if (is_array($globalModules) && isset($globalModules[$candidate]) && ! ($globalModules[$candidate]['is_active'] ?? true)) {
                return [
                    'allowed' => false,
                    'status' => 503,
                    'code' => 'MODULE_DISABLED',
                    'message' => "Module '{$normalized}' is temporarily unavailable due to a platform kill-switch.",
                ];
            }
        }

        // 2. Verified platform super_admin bypasses per-tenant subscription requirement
        if ($user && (! empty($user->isPlatformSuperAdmin) || in_array('super_admin', $user->scopedRoles ?? [], true) || $user->role === 'super_admin')) {
            return ['allowed' => true];
        }

        // 3. Core platform modules are always entitled when globally active
        if (is_array($globalModules)) {
            foreach ($candidates as $candidate) {
                if (! empty($globalModules[$candidate]['is_core'])) {
                    return ['allowed' => true];
                }
            }
        }

        // 4. Tenant-scoped subscription check via authorize-context or shared Redis cache
        $tenantId = $authContext['tenant_id'] ?? $user?->tenant_id ?? $request->attributes->get('auth_tenant_id');
        $tenantId = is_numeric($tenantId) ? (int) $tenantId : null;

        $enabledModules = null;
        if (array_key_exists('enabled_modules', $authContext) && is_array($authContext['enabled_modules'])) {
            $enabledModules = array_map(fn ($id) => strtolower((string) $id), $authContext['enabled_modules']);
        } elseif ($tenantId !== null) {
            $enabledModules = $this->getCachedTenantModules($tenantId);
        }

        // If neither authorize-context nor Redis has entitlement metadata, allow (isolated/unconfigured fallback)
        if ($enabledModules === null && $globalModules === null) {
            return ['allowed' => true];
        }

        if (! $tenantId) {
            return [
                'allowed' => false,
                'status' => 403,
                'code' => 'MODULE_NOT_SUBSCRIBED',
                'message' => "Organization context is required to access module '{$normalized}'.",
            ];
        }

        if ($enabledModules === null) {
            // Global modules are cached but tenant cache hasn't been warmed yet: fail closed only if tenant cache key exists as empty
            return ['allowed' => true];
        }

        foreach ($candidates as $candidate) {
            if (in_array($candidate, $enabledModules, true)) {
                return ['allowed' => true];
            }
        }

        return [
            'allowed' => false,
            'status' => 403,
            'code' => 'MODULE_NOT_SUBSCRIBED',
            'message' => "Your organization is not subscribed to module '{$normalized}'.",
        ];
    }

    /**
     * @return array<string, array{is_active: bool, is_core: bool}>|null
     */
    protected function getGlobalModules(): ?array
    {
        return $this->readFromCaches(self::GLOBAL_CACHE_KEY);
    }

    /**
     * @return array<int, string>|null
     */
    protected function getCachedTenantModules(int $tenantId): ?array
    {
        return $this->readFromCaches(self::TENANT_CACHE_PREFIX.$tenantId);
    }

    protected function readFromCaches(string $key): ?array
    {
        try {
            $local = Cache::get($key);
            if (is_array($local)) {
                return $local;
            }
        } catch (\Throwable) {
        }

        $shared = $this->sharedStore();
        if ($shared) {
            try {
                $val = $shared->get($key);
                if (is_array($val)) {
                    return $val;
                }
            } catch (\Throwable) {
            }
        }

        return null;
    }

    protected function sharedStore(): ?Repository
    {
        if (! config('cache.stores.revocation.enabled', false) && config('cache.default') !== 'redis') {
            return null;
        }

        try {
            return Cache::store('revocation');
        } catch (\Throwable) {
            return null;
        }
    }
}
