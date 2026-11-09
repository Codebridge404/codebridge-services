<?php

namespace App\Http\Middleware;

use App\Services\UserAuthorizationService;
use App\Support\SchemaGuard;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * [BD-0086] Role Verification & Tenant Privilege Boundary Guard Middleware
 *
 * @ticket BD-0086
 * @component auth-service
 * @file codebridge-services/auth-service/app/Http/Middleware/EnsureRole.php
 * @related-model App\Models\User, App\Models\Role
 * @entry-point EnsureRole::handle()
 * @routes
 *  - Middleware: role:role_name
 * @date-time 2026-10-23T15:40:00+07:00
 *
 * Process Capabilities:
 * Step 1 (Authenticated User Extraction): Retrieves authenticated user from JWT token context.
 * Step 2 (Parameter Role Matching): Compares allowed role parameters against user's assigned roles in user_roles pivot.
 * Step 3 (Superadmin Bypass): Grants automatic passthrough for platform superadmin role.
 * Step 4 (Unauthorized Rejection): Halts request with HTTP 403 Forbidden if user lacks required role credentials.
 * Step 5 (Request Context Enrichment): Injects verified role slug into request for downstream controller logic.
 */
class EnsureRole
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(Request): (Response)  $next
     * @param  string  ...$roles
     */
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        $user = $request->user();

        if (!$user) {
            try {
                if (SchemaGuard::hasTable('security_events')) {
                    \App\Models\SecurityEvent::record(
                        eventType: 'unauthenticated',
                        ruleCode: 'SEC_UNAUTHENTICATED',
                        summary: 'Role verification failed: user is unauthenticated',
                        severity: 'low',
                        outcome: 'blocked',
                        confidence: 'confirmed',
                        request: $request,
                        statusCode: 401
                    );
                }
            } catch (\Throwable) {}

            return response()->json([
                'status' => 'error',
                'message' => 'Unauthenticated.',
            ], 401);
        }

        // Resolve verified tenant-scoped role context behind the Auth authorization contract
        $claimTenantId = $request->attributes->get('jwt_tenant_id');
        if (!empty($user->exists) && !empty($user->id)) {
            $context = UserAuthorizationService::resolveAuthorizationContext(
                $user,
                null,
                is_numeric($claimTenantId) ? (int) $claimTenantId : null,
                false
            );

            if (!$context['authorized']) {
                $status = (int) ($context['status'] ?? 403);
                if ($status === 401) {
                    return response()->json([
                        'status' => 'error',
                        'code' => $context['code'] ?? 'UNAUTHENTICATED',
                        'message' => $context['message'] ?? 'Unauthenticated.',
                    ], 401);
                }

                return response()->json([
                    'status' => 'error',
                    'code' => $context['code'] ?? 'TENANT_ACCESS_DENIED',
                    'message' => $context['message'] ?? 'Forbidden.',
                ], $status);
            }

            if (!empty($context['is_super_admin'])) {
                return $next($request);
            }

            $userRoles = $context['roles'] ?? [];
            $primaryRole = $context['role'] ?? 'user';
            $resolvedTenantId = $context['tenant_id'] ?? $user->tenant_id;
        } else {
            if (UserAuthorizationService::isSuperAdmin($user)) {
                return $next($request);
            }
            $userRoles = array_filter([$user->role]);
            $primaryRole = $user->role ?? 'user';
            $resolvedTenantId = $user->tenant_id;
        }

        // Normalize direct linguistic synonyms (administrator <=> admin) without merging distinct privilege tiers (owner, organization, admin)
        $effectiveRoles = array_unique($userRoles);
        if (in_array('administrator', $effectiveRoles, true)) {
            $effectiveRoles[] = 'admin';
        }
        if (in_array('admin', $effectiveRoles, true)) {
            $effectiveRoles[] = 'administrator';
        }
        $effectiveRoles = array_unique($effectiveRoles);

        if (empty(array_intersect($effectiveRoles, $roles))) {
            try {
                if (SchemaGuard::hasTable('security_events')) {
                    \App\Models\SecurityEvent::record(
                        eventType: 'role_access_denied',
                        ruleCode: 'SEC_ROLE_DENIED',
                        summary: "Forbidden: user role [{$primaryRole}] lacks required roles [" . implode(', ', $roles) . "]",
                        severity: 'medium',
                        outcome: 'blocked',
                        confidence: 'confirmed',
                        userId: $user->id,
                        tenantId: $resolvedTenantId,
                        request: $request,
                        metadata: [
                            'user_role' => $primaryRole,
                            'scoped_roles' => array_values($effectiveRoles),
                            'required_roles' => $roles,
                        ],
                        statusCode: 403
                    );
                }
            } catch (\Throwable) {}

            return response()->json([
                'status' => 'error',
                'message' => 'Forbidden. Your role [' . $primaryRole . '] does not have access to this resource.',
            ], 403);
        }

        return $next($request);
    }
}
