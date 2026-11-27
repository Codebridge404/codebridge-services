<?php

namespace App\Services;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * Publishes and reads real-time shared Redis security state across microservices.
 */
class SharedSecurityStateService
{
    public const TENANT_STATUS_PREFIX = 'tenant_status:';

    public const TENANT_STATUS_VERSION_PREFIX = 'tenant_status_version:';

    public const TTL_SECONDS = 86400;

    public static function publishTenantStatus(int $tenantId, string $status): void
    {
        $statusKey = self::TENANT_STATUS_PREFIX.$tenantId;
        $versionKey = self::TENANT_STATUS_VERSION_PREFIX.$tenantId;
        $normalized = strtolower(trim($status));

        try {
            Cache::put($statusKey, $normalized, self::TTL_SECONDS);
            $nextVer = ((int) Cache::get($versionKey, 0)) + 1;
            Cache::put($versionKey, $nextVer, self::TTL_SECONDS);
        } catch (\Throwable) {
        }

        $shared = self::sharedStore();
        if ($shared) {
            try {
                $shared->put($statusKey, $normalized, self::TTL_SECONDS);
                $nextSharedVer = ((int) $shared->get($versionKey, 0)) + 1;
                $shared->put($versionKey, $nextSharedVer, self::TTL_SECONDS);
            } catch (\Throwable) {
            }
        }
    }

    public static function getTenantStatus(int $tenantId): ?string
    {
        $statusKey = self::TENANT_STATUS_PREFIX.$tenantId;

        $shared = self::sharedStore();
        if ($shared) {
            try {
                $val = $shared->get($statusKey);
                if (is_string($val) && $val !== '') {
                    return strtolower($val);
                }
            } catch (\Throwable) {
            }
        }

        try {
            $local = Cache::get($statusKey);
            if (is_string($local) && $local !== '') {
                return strtolower($local);
            }
        } catch (\Throwable) {
        }

        return null;
    }

    public static function isTenantSuspended(?int $tenantId): bool
    {
        if (! $tenantId) {
            return false;
        }

        return self::getTenantStatus($tenantId) === 'suspended';
    }

    public static function sharedStore(): ?Repository
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
