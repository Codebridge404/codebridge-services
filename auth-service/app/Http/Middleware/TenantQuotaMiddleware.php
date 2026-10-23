<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * [BD-0039] Multi-Tenant Resource Quota Enforcement Middleware.
 *
 * @ticket BD-0039
 * @component auth-service
 * @file codebridge-services/auth-service/app/Http/Middleware/TenantQuotaMiddleware.php
 * @related-model App\Models\TenantSubscription, App\Models\UserRole
 * @date-time 2026-10-23T15:35:00+07:00
 *
 * Enforces seat quotas for staff, outlets, and registers per subscription tier.
 */
class TenantQuotaMiddleware
{
    /**
     * Handle an incoming request to enforce multi-tenant subscription quotas.
     *
     * @param  \Closure(Request): (Response)  $next
     * @param  string  $resourceType ('users', 'outlets', 'registers')
     */
    public function handle(Request $request, Closure $next, string $resourceType = 'users'): Response
    {
        $user = $request->user();

        // If user is super_admin, quota checks are bypassed
        if ($user && $user->role === 'super_admin') {
            return $next($request);
        }

        // Resolve Tenant
        $tenantId = $user?->tenant_id;
        $activeWorkspace = $request->header('X-Tenant-Workspace');

        $tenantQuery = DB::table('tenants');
        if ($tenantId) {
            $tenantQuery->where('id', $tenantId);
        } elseif (!empty($activeWorkspace) && $activeWorkspace !== 'No Organization Yet! Please Create') {
            $tenantQuery->where('name', $activeWorkspace)->orWhere('slug', $activeWorkspace);
        }

        $tenant = $tenantQuery->first();

        if (!$tenant) {
            // If no tenant is bound yet, allow standard operational fallback
            return $next($request);
        }

        // Quota limits based on tenant or tier defaults
        $maxUsers = $tenant->max_users ?? match ($tenant->client_tier) {
            'enterprise_org' => 500,
            'business_runner' => 50,
            default => 5,
        };

        $maxOutlets = $tenant->max_outlets ?? match ($tenant->client_tier) {
            'enterprise_org' => 50,
            'business_runner' => 5,
            default => 1,
        };

        $maxRegisters = $tenant->max_registers ?? match ($tenant->client_tier) {
            'enterprise_org' => 200,
            'business_runner' => 15,
            default => 2,
        };

        if ($resourceType === 'users') {
            $currentUsersCount = DB::table('users as u')
                ->leftJoin('user_roles as ur', function ($j) {
                    $j->on('u.id', '=', 'ur.user_id')->where('ur.is_active', true);
                })
                ->leftJoin('roles as r', 'ur.role_id', '=', 'r.id')
                ->where(function ($q) use ($tenant) {
                    $q->where('u.tenant_id', $tenant->id)
                      ->orWhereNull('u.tenant_id');
                })
                ->where(function ($q) {
                    $q->whereNull('r.slug')->orWhere('r.slug', '!=', 'super_admin');
                    if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'role')) {
                        $q->orWhere('u.role', '!=', 'super_admin');
                    }
                })
                ->count();

            if ($currentUsersCount >= $maxUsers) {
                return response()->json([
                    'status' => 'error',
                    'code' => 'QUOTA_EXCEEDED',
                    'message' => "User limit reached ({$currentUsersCount}/{$maxUsers}) for your '{$tenant->client_tier}' subscription. Please upgrade to add more staff members.",
                    'quota' => [
                        'resource' => 'users',
                        'current' => $currentUsersCount,
                        'limit' => $maxUsers,
                        'tier' => $tenant->client_tier,
                    ],
                ], 403);
            }
        } elseif ($resourceType === 'outlets') {
            $currentOutletsCount = DB::table('outlets')->count();

            if ($currentOutletsCount >= $maxOutlets) {
                return response()->json([
                    'status' => 'error',
                    'code' => 'QUOTA_EXCEEDED',
                    'message' => "Store outlet limit reached ({$currentOutletsCount}/{$maxOutlets}) for your '{$tenant->client_tier}' subscription. Please upgrade to open additional store branches.",
                    'quota' => [
                        'resource' => 'outlets',
                        'current' => $currentOutletsCount,
                        'limit' => $maxOutlets,
                        'tier' => $tenant->client_tier,
                    ],
                ], 403);
            }
        } elseif ($resourceType === 'registers') {
            $currentRegistersCount = DB::table('registers')->count();

            if ($currentRegistersCount >= $maxRegisters) {
                return response()->json([
                    'status' => 'error',
                    'code' => 'QUOTA_EXCEEDED',
                    'message' => "POS Register limit reached ({$currentRegistersCount}/{$maxRegisters}) for your '{$tenant->client_tier}' subscription. Please upgrade to add more POS terminals.",
                    'quota' => [
                        'resource' => 'registers',
                        'current' => $currentRegistersCount,
                        'limit' => $maxRegisters,
                        'tier' => $tenant->client_tier,
                    ],
                ], 403);
            }
        }

        return $next($request);
    }
}
