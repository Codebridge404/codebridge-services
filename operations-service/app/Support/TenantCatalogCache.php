<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Tenant-scoped, versioned Redis/Cache helper for catalog & operational settings reads.
 *
 * Isolation guarantees:
 * - Every cache key embeds the normalized tenant scope ('global', 'all', or tenant ID),
 *   all query parameters / visibility flags (e.g. authenticated vs public), and dual version tokens.
 * - Platform/global writes (tenantId === null) bump the 'global' epoch so empty tenants
 *   that fall back to shared template items see updates immediately, while tenant-specific
 *   writes only invalidate that tenant's namespace (plus 'all' for super-admin queries).
 */
class TenantCatalogCache
{
    public const DEFAULT_TTL_SECONDS = 60;

    /**
     * Normalize tenant scope for cache key isolation.
     */
    public static function normalizeScope(?string $tenantId): string
    {
        if ($tenantId === null) {
            return 'global';
        }

        $trimmed = trim($tenantId);
        if ($trimmed === '') {
            return 'global';
        }

        if ($trimmed === 'all') {
            return 'all';
        }

        return 't_' . sha1($trimmed);
    }

    /**
     * Get the combined version string for a namespace and tenant scope.
     */
    public static function getVersion(string $namespace, ?string $tenantId): string
    {
        $scope = self::normalizeScope($tenantId);
        $globalVer = (string) Cache::get(self::versionKey($namespace, 'global'), '0');

        if ($scope === 'global') {
            return $globalVer;
        }

        if ($scope === 'all') {
            $allVer = (string) Cache::get(self::versionKey($namespace, 'all'), '0');
            return "{$globalVer}:{$allVer}";
        }

        $tenantVer = (string) Cache::get(self::versionKey($namespace, $scope), '0');
        return "{$globalVer}:{$tenantVer}";
    }

    /**
     * Build a deterministic, collision-resistant cache key for a tenant + namespace + query params.
     *
     * @param  array<string, mixed>  $params
     */
    public static function buildKey(string $namespace, ?string $tenantId, array $params = []): string
    {
        ksort($params);
        $scope = self::normalizeScope($tenantId);
        $version = self::getVersion($namespace, $tenantId);
        $encoded = json_encode($params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $paramHash = hash('sha256', $encoded === false ? serialize($params) : $encoded);

        return "catalog:{$namespace}:{$scope}:v:{$version}:{$paramHash}";
    }

    /**
     * Remember a query result under a versioned, tenant-scoped cache key.
     *
     * @template T
     * @param  array<string, mixed>  $params
     * @param  callable(): T  $resolver
     * @return T
     */
    public static function remember(
        string $namespace,
        ?string $tenantId,
        array $params,
        callable $resolver,
        int $ttlSeconds = self::DEFAULT_TTL_SECONDS
    ): mixed {
        $key = self::buildKey($namespace, $tenantId, $params);
        $ttl = max(1, $ttlSeconds);

        return Cache::remember($key, $ttl, $resolver);
    }

    /**
     * Bump the cache version for one or more namespaces for the affected tenant.
     *
     * @param  string|list<string>  $namespaces
     */
    public static function invalidate(?string $tenantId, string|array $namespaces = 'products'): void
    {
        $targets = is_array($namespaces) ? $namespaces : [$namespaces];
        $scope = self::normalizeScope($tenantId);

        foreach ($targets as $namespace) {
            $nextVersion = (string) Str::uuid();

            if ($scope === 'global' || $scope === 'all') {
                Cache::forever(self::versionKey($namespace, 'global'), $nextVersion);
                Cache::forever(self::versionKey($namespace, 'all'), $nextVersion);
            } else {
                Cache::forever(self::versionKey($namespace, $scope), $nextVersion);
                Cache::forever(self::versionKey($namespace, 'all'), (string) Str::uuid());
            }
        }
    }

    protected static function versionKey(string $namespace, string $scope): string
    {
        return "catalog_ver:{$namespace}:{$scope}";
    }
}

