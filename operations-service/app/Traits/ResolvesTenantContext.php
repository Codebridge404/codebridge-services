<?php

namespace App\Traits;

use App\Services\TenantAuthorizationService;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Trait ResolvesTenantContext
 *
 * Enforces strict multi-tenant boundary checks across Operations microservice.
 * Delegates authenticated resolution through TenantAuthorizationService (Auth contract).
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

        // 1. Authenticated User Flow (delegated to centralized Auth authorization contract)
        if ($user) {
            $jwtClaims = $request->attributes->get('jwt_claims');
            $context = TenantAuthorizationService::resolveAuthorizationContext(
                $request,
                $user,
                is_array($jwtClaims) ? $jwtClaims : null,
                $requireTenant
            );

            if (empty($context['authorized'])) {
                abort((int) ($context['status'] ?? 403), (string) ($context['message'] ?? 'Access denied.'));
            }

            if (($context['requested_tenant_scope'] ?? null) === 'all') {
                return 'all';
            }

            return isset($context['tenant_id']) && $context['tenant_id'] !== null
                ? (string) $context['tenant_id']
                : null;
        }

        // 2. Unauthenticated Public Flow (e.g. public storefronts viewing published catalog)
        $parsed = TenantAuthorizationService::parseRequestedTenant($request);
        if ($parsed['conflict']) {
            abort(403, 'Conflicting tenant context supplied between header and query parameter.');
        }
        if ($parsed['scope'] === 'all') {
            abort(403, 'Unauthenticated cross-tenant queries are forbidden.');
        }
        if ($parsed['invalid']) {
            abort(403, 'Invalid tenant identifier supplied.');
        }

        if ($requireTenant && empty($parsed['scope'])) {
            abort(401, 'Authentication or valid organization identifier is required.');
        }

        return $parsed['scope'];
    }

    /**
     * Verify that an existing resource belongs to the current tenant context.
     * Only platform-wide super_admin may operate across tenant boundaries.
     *
     * @param string|int|null $resourceTenantId
     * @param Request $request
     * @param string $resourceName
     * @throws HttpException
     */
    protected function authorizeTenantResource($resourceTenantId, Request $request, string $resourceName = 'Resource'): void
    {
        $user = $request->user();
        if ($user) {
            $jwtClaims = $request->attributes->get('jwt_claims');
            $context = TenantAuthorizationService::resolveAuthorizationContext(
                $request,
                $user,
                is_array($jwtClaims) ? $jwtClaims : null,
                false
            );

            if (empty($context['authorized'])) {
                abort((int) ($context['status'] ?? 403), (string) ($context['message'] ?? 'Access denied.'));
            }

            if (!empty($context['is_platform_super_admin'])) {
                return;
            }
        }

        $tenantId = $this->getTenantId($request, true);

        // If resource is explicitly assigned to a tenant, it must match
        if ($resourceTenantId !== null && (string) $resourceTenantId !== (string) $tenantId) {
            abort(403, "Unauthorized: {$resourceName} belongs to another organization.");
        }

        // Global/template records cannot be modified or deleted by non-platform-superadmins
        if ($resourceTenantId === null) {
            abort(403, "Unauthorized: Global template {$resourceName}s cannot be modified directly.");
        }
    }
}
