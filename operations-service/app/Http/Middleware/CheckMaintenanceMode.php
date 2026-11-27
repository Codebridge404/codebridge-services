<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces Platform Maintenance Mode in Operations Service using shared Redis state
 * (platform:maintenance:config), returning HTTP 503 on non-super_admin mutations.
 */
class CheckMaintenanceMode
{
    public const CACHE_KEY = 'platform:maintenance:config';

    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array(strtoupper($request->method()), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $next($request);
        }

        $path = ltrim($request->path(), '/');
        if ($path === 'api/v1/health' || $path === 'up') {
            return $next($request);
        }

        $user = $request->user();
        $isSuperAdmin = $user && (! empty($user->isPlatformSuperAdmin)
            || in_array('super_admin', $user->scopedRoles ?? [], true)
            || ($user->role ?? null) === 'super_admin');

        if ($isSuperAdmin) {
            return $next($request);
        }

        $config = $this->readMaintenanceConfig();
        if (! is_array($config) || empty($config['enabled']) || empty($config['block_mutations'])) {
            return $next($request);
        }

        if (! $this->isCurrentlyActive($config)) {
            return $next($request);
        }

        return response()->json([
            'status' => 'error',
            'code' => 'MAINTENANCE_MODE',
            'message' => 'The platform is undergoing maintenance. Please try again later.',
            'maintenance' => [
                'message' => $config['message'] ?? null,
                'starts_at' => $config['starts_at'] ?? null,
                'ends_at' => $config['ends_at'] ?? null,
            ],
        ], 503);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function readMaintenanceConfig(): ?array
    {
        try {
            $local = Cache::get(self::CACHE_KEY);
            if (is_array($local)) {
                return $local;
            }
        } catch (\Throwable) {
        }

        if (config('cache.stores.revocation.enabled', false) || config('cache.default') === 'redis') {
            try {
                $shared = Cache::store('revocation')->get(self::CACHE_KEY);
                if (is_array($shared)) {
                    return $shared;
                }
            } catch (\Throwable) {
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function isCurrentlyActive(array $config): bool
    {
        $now = Carbon::now('UTC');
        $startsAt = ! empty($config['starts_at']) ? Carbon::parse((string) $config['starts_at'])->utc() : null;
        $endsAt = ! empty($config['ends_at']) ? Carbon::parse((string) $config['ends_at'])->utc() : null;

        if ($startsAt && $now->lt($startsAt)) {
            return false;
        }
        if ($endsAt && $now->gt($endsAt)) {
            return false;
        }

        return true;
    }
}
