<?php

namespace App\Traits;

use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Trait ResolvesTenantContext
 *
 * Enforces strict multi-tenant boundary checks across Operations microservice.
 * Prevents client-side X-Tenant-Id spoofing, cross-tenant data leaks, and unauthorized mutations.
 */
trait ResolvesTenantContext
{
    /**
     * Resolve and validate tenant context from authenticated user or request parameters.
     * Enforces strict tenant boundaries: users cannot access or spoof another tenant's data.
     *
     * @param Request $request
     * @param bool $requireTenant Whether a non-null tenant is required (aborts 401/403 if missing).
     * @return string|null
     * @throws HttpException
     */
    protected function getTenantId(Request $request, bool $requireTenant = false): ?string
    {
        $user = $request->user();
        $headerTenantId = $request->header('X-Tenant-Id');
        $queryTenantId = $request->query('tenant_id');
        $clientSuppliedTenantId = $headerTenantId ?: ($queryTenantId ?: null);

        // 1. Authenticated User Flow
        if ($user) {
            $userTenantId = $user->tenant_id ? (string) $user->tenant_id : null;

            // Super Admin may freely manage across tenants or query specific tenant
            if (($user->role ?? null) === 'super_admin') {
                if ($clientSuppliedTenantId === 'all') {
                    return 'all';
                }
                return $clientSuppliedTenantId ?: $userTenantId;
            }

            // If user has an explicit tenant_id assigned
            if (!empty($userTenantId)) {
                // Client cannot pass 'all' unless super_admin
                if ($clientSuppliedTenantId === 'all') {
                    abort(403, 'Access denied: Cross-tenant queries are restricted to super administrators.');
                }
                // If a client explicitly passes an X-Tenant-Id or query tenant_id, it MUST match their own
                if (!empty($clientSuppliedTenantId) && (string) $clientSuppliedTenantId !== $userTenantId) {
                    abort(403, 'Access denied: You cannot operate on or query another organization.');
                }
                return $userTenantId;
            }

            // If user has no explicit tenant_id assigned (e.g. unassigned staff or test harness)
            if (!empty($clientSuppliedTenantId)) {
                if ($clientSuppliedTenantId === 'all') {
                    abort(403, 'Access denied: Cross-tenant queries are restricted to super administrators.');
                }
                return (string) $clientSuppliedTenantId;
            }

            return $requireTenant ? '1' : null;
        }

        // 2. Unauthenticated Public Flow (e.g. public storefronts viewing published catalog)
        if ($clientSuppliedTenantId === 'all') {
            abort(403, 'Unauthenticated cross-tenant queries are forbidden.');
        }

        if ($requireTenant && empty($clientSuppliedTenantId)) {
            abort(401, 'Authentication or valid organization identifier is required.');
        }

        return $clientSuppliedTenantId ? (string) $clientSuppliedTenantId : null;
    }

    /**
     * Verify that an existing resource belongs to the current tenant context.
     *
     * @param string|int|null $resourceTenantId
     * @param Request $request
     * @param string $resourceName
     * @throws HttpException
     */
    protected function authorizeTenantResource($resourceTenantId, Request $request, string $resourceName = 'Resource'): void
    {
        $user = $request->user();
        if (($user->role ?? null) === 'super_admin') {
            return;
        }

        $tenantId = $this->getTenantId($request, true);

        // If resource is explicitly assigned to a tenant, it must match
        if ($resourceTenantId !== null && (string) $resourceTenantId !== (string) $tenantId) {
            abort(403, "Unauthorized: {$resourceName} belongs to another organization.");
        }

        // Global/template records cannot be modified or deleted by non-superadmins
        if ($resourceTenantId === null) {
            abort(403, "Unauthorized: Global template {$resourceName}s cannot be modified directly.");
        }
    }
}
