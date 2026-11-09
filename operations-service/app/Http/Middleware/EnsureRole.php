<?php

namespace App\Http\Middleware;

use App\Services\TenantAuthorizationService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * [BD-0113] Operations Role-Based Access Control Enforcement Middleware
 *
 * @ticket BD-0113
 * @component operations-service
 * @file codebridge-services/operations-service/app/Http/Middleware/EnsureRole.php
 * @related-model App\Services\TenantAuthorizationService
 * @entry-point EnsureRole::handle()
 * @routes
 *  - Middleware: role:role_name
 * @date-time 2026-10-23T15:40:00+07:00
 *
 * Process Capabilities:
 * Step 1 (Auth Contract Resolution): Resolves verified tenant context and tenant-scoped roles via TenantAuthorizationService.
 * Step 2 (Privilege Matrix Matching): Checks whether user's tenant-scoped roles match the required endpoint permission roles.
 * Step 3 (Platform Role Evaluation): Handles platform-wide super_admin vs tenant-scoped super_admin explicitly.
 * Step 4 (Forbidden Interception): Returns HTTP 403 Forbidden with security error message on unauthorized access.
 * Step 5 (Downstream Forwarding): Passes authorized request forward to target controller.
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
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $jwtClaims = $request->attributes->get('jwt_claims');
        $context = TenantAuthorizationService::resolveAuthorizationContext(
            $request,
            $user,
            is_array($jwtClaims) ? $jwtClaims : null,
            false
        );

        if (empty($context['authorized'])) {
            return response()->json([
                'status' => 'error',
                'code' => $context['code'] ?? 'UNAUTHORIZED',
                'message' => $context['message'] ?? 'Forbidden.',
            ], (int) ($context['status'] ?? 403));
        }

        $request->attributes->set('auth_context', $context);

        $scopedRoles = is_array($context['roles'] ?? null) ? $context['roles'] : [];
        $primaryRole = (string) ($context['role'] ?? 'user');
        $isSuperAdmin = (bool) ($context['is_super_admin'] ?? false);

        if (!empty($context['tenant_id']) && empty($user->pendingTenantId)) {
            $user->pendingTenantId = (string) $context['tenant_id'];
        }
        $user->scopedRoles = $scopedRoles;
        $user->pendingRole = $primaryRole;
        $user->isPlatformSuperAdmin = (bool) ($context['is_platform_super_admin'] ?? false);
        $user->resolvedSuperAdmin = $isSuperAdmin;

        if (!TenantAuthorizationService::roleGrantsAccess($scopedRoles, $roles, $isSuperAdmin)) {
            return response()->json([
                'status' => 'error',
                'code' => 'ROLE_FORBIDDEN',
                'message' => 'Forbidden. Your role [' . $primaryRole . '] does not have access to this resource.',
            ], 403);
        }

        return $next($request);
    }
}
