<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Tenant-scoped, versioned Redis/Cache helper for Auth workspace & outlet settings.
 *
 * Uses UUID version tokens so cache invalidation works identically across array,
 * file, database, and phpredis cache drivers without INCRBY/serializer mismatches.
 */
class TenantSettingsCache
{
    public const DEFAULT_TTL_SECONDS = 60;

    public static function normalizeScope(?string $tenantId): string
    {
        if ($tenantId === null) {
            return 'global';
        }

        $trimmed = trim($tenantId);
        if ($trimmed === '') {
            return 'global';
        }

        if ($trimmed === 'super') {
            return 'super';
        }

        return 't_' . sha1($trimmed);
    }

    public static function rememberOutlet(
        string $section,
        string $callerScope,
        int|string $outletId,
        callable $resolver,
        int $ttlSeconds = self::DEFAULT_TTL_SECONDS
    ): mixed {
        $scope = self::normalizeScope($callerScope);
        $globalVer = (string) Cache::get('settings_ver:outlets:global', '0');
        $outletVer = (string) Cache::get("settings_ver:outlet:{$outletId}", '0');
        $key = "settings:{$section}:{$scope}:outlet:{$outletId}:v:{$globalVer}:{$outletVer}";

        return Cache::remember($key, max(1, $ttlSeconds), $resolver);
    }

    public static function invalidateOutlet(?string $tenantId, int|string $outletId): void
    {
        Cache::forever("settings_ver:outlet:{$outletId}", (string) Str::uuid());
        if ($tenantId !== null && trim($tenantId) !== '') {
            Cache::forever('settings_ver:outlets:t_' . sha1(trim($tenantId)), (string) Str::uuid());
        } else {
            Cache::forever('settings_ver:outlets:global', (string) Str::uuid());
        }
    }

    public static function rememberMeetingSettings(?string $scopeId, callable $resolver, int $ttlSeconds = self::DEFAULT_TTL_SECONDS): mixed
    {
        $scope = self::normalizeScope($scopeId);
        $globalVer = (string) Cache::get('settings_ver:meetings:global', '0');
        $scopeVer = (string) Cache::get("settings_ver:meetings:{$scope}", '0');
        $key = "settings:meetings:{$scope}:v:{$globalVer}:{$scopeVer}";

        return Cache::remember($key, max(1, $ttlSeconds), $resolver);
    }

    public static function invalidateMeetingSettings(?string $tenantId): void
    {
        if ($tenantId !== null && trim($tenantId) !== '') {
            $scope = self::normalizeScope($tenantId);
            Cache::forever("settings_ver:meetings:{$scope}", (string) Str::uuid());
            Cache::forever('settings_ver:meetings:super', (string) Str::uuid());
        } else {
            Cache::forever('settings_ver:meetings:global', (string) Str::uuid());
            Cache::forever('settings_ver:meetings:super', (string) Str::uuid());
        }
    }
}

