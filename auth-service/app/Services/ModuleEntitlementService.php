<?php

namespace App\Services;

use App\Models\Auth\User;
use App\Support\SchemaGuard;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Cross-Service Module Entitlement & Global Kill-Switch Service.
 *
 * Evaluates platform-wide module kill-switches (platform_modules.is_active) and
 * per-tenant subscriptions (tenant_modules / tenants.enabled_modules), backed
 * by Redis cache with automatic database fallback.
 */
class ModuleEntitlementService
{
    public const GLOBAL_CACHE_KEY = 'platform:modules:global';

    public const TENANT_CACHE_PREFIX = 'platform:modules:tenant:';

    public const CACHE_TTL_SECONDS = 300;

    /**
     * Evaluate whether a user/tenant may access the given module.
     *
     * @return array{allowed: bool, status?: int, code?: string, message?: string}
     */
    public function checkAccess(string $moduleId, ?int $tenantId, ?User $user = null): array
    {
        $normalized = strtolower(trim($moduleId));
        $globalModules = $this->getGlobalModules();
        $module = $globalModules[$normalized] ?? null;

        // 1. Global kill-switch: if registered and is_active=false, return 503 for everyone
        if ($module !== null && ! $module['is_active']) {
            return [
                'allowed' => false,
                'status' => 503,
                'code' => 'MODULE_DISABLED',
                'message' => "Module '{$normalized}' is temporarily unavailable due to a platform kill-switch.",
            ];
        }

        // 2. Verified platform super_admin bypasses per-tenant subscription requirement
        if ($user && UserAuthorizationService::hasPlatformSuperAdminRole($user)) {
            return ['allowed' => true];
        }

        // 3. Core platform modules are always entitled when globally active
        if ($module !== null && $module['is_core']) {
            return ['allowed' => true];
        }

        // 4. Tenant-scoped subscription check
        if (! $tenantId) {
            return [
                'allowed' => false,
                'status' => 403,
                'code' => 'MODULE_NOT_SUBSCRIBED',
                'message' => "Organization context is required to access module '{$normalized}'.",
            ];
        }

        $enabledForTenant = $this->getEnabledModulesForTenant($tenantId);
        if (! in_array($normalized, $enabledForTenant, true)) {
            return [
                'allowed' => false,
                'status' => 403,
                'code' => 'MODULE_NOT_SUBSCRIBED',
                'message' => "Your organization is not subscribed to module '{$normalized}'.",
            ];
        }

        return ['allowed' => true];
    }

    /**
     * Return active module IDs for a tenant (from Redis cache or DB).
     *
     * @return array<int, string>
     */
    public function getEnabledModulesForTenant(?int $tenantId): array
    {
        if (! $tenantId) {
            return [];
        }

        $cacheKey = self::TENANT_CACHE_PREFIX.$tenantId;

        try {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        } catch (\Throwable) {
            // Fall through to DB
        }

        $modules = $this->loadTenantModulesFromDb($tenantId);

        try {
            Cache::put($cacheKey, $modules, self::CACHE_TTL_SECONDS);
        } catch (\Throwable) {
            // Safe fall-through
        }

        return $modules;
    }

    /**
     * Return globally disabled module IDs (kill-switched).
     *
     * @return array<int, string>
     */
    public function getGloballyDisabledModules(): array
    {
        $disabled = [];
        foreach ($this->getGlobalModules() as $id => $meta) {
            if (! $meta['is_active']) {
                $disabled[] = $id;
            }
        }

        return $disabled;
    }

    public function invalidateGlobalCache(): void
    {
        try {
            Cache::forget(self::GLOBAL_CACHE_KEY);
        } catch (\Throwable) {
        }
    }

    public function invalidateTenantCache(int $tenantId): void
    {
        try {
            Cache::forget(self::TENANT_CACHE_PREFIX.$tenantId);
        } catch (\Throwable) {
        }
    }

    /**
     * @return array<string, array{is_active: bool, is_core: bool}>
     */
    protected function getGlobalModules(): array
    {
        try {
            $cached = Cache::get(self::GLOBAL_CACHE_KEY);
            if (is_array($cached)) {
                return $cached;
            }
        } catch (\Throwable) {
            // Fall through to DB
        }

        $modules = [];
        if (SchemaGuard::hasTable('platform_modules')) {
            $rows = DB::table('platform_modules')->get(['id', 'is_active', 'is_core']);
            foreach ($rows as $row) {
                $modules[strtolower((string) $row->id)] = [
                    'is_active' => (bool) $row->is_active,
                    'is_core' => (bool) $row->is_core,
                ];
            }
        }

        try {
            Cache::put(self::GLOBAL_CACHE_KEY, $modules, self::CACHE_TTL_SECONDS);
        } catch (\Throwable) {
        }

        return $modules;
    }

    /**
     * @return array<int, string>
     */
    protected function loadTenantModulesFromDb(int $tenantId): array
    {
        if (SchemaGuard::hasTable('tenant_modules')) {
            $rows = DB::table('tenant_modules')
                ->where('tenant_id', $tenantId)
                ->get(['module_id', 'is_active']);

            if ($rows->isNotEmpty()) {
                return $rows
                    ->filter(fn ($r) => (bool) $r->is_active)
                    ->pluck('module_id')
                    ->map(fn ($id) => strtolower((string) $id))
                    ->values()
                    ->all();
            }
        }

        if (SchemaGuard::hasTable('tenants')) {
            $raw = DB::table('tenants')->where('id', $tenantId)->value('enabled_modules');
            $decoded = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : []);
            if (is_array($decoded)) {
                return array_values(array_map(fn ($id) => strtolower((string) $id), $decoded));
            }
        }

        return [];
    }
}
