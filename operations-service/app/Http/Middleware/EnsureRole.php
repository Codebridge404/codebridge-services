<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * [BD-0113] Operations Role-Based Access Control Enforcement Middleware
 *
 * @ticket BD-0113
 * @component operations-service
 * @file codebridge-services/operations-service/app/Http/Middleware/EnsureRole.php
 * @related-model App\Services\JwtService
 * @entry-point EnsureRole::handle()
 * @routes
 *  - Middleware: role:role_name
 * @date-time 2026-10-23T15:40:00+07:00
 *
 * Process Capabilities:
 * Step 1 (Token Role Extraction): Decodes user role slug from verified JWT payload.
 * Step 2 (Privilege Matrix Matching): Checks whether user role matches the required endpoint permission role.
 * Step 3 (Manager Override Evaluation): Permits tenant owner and manager roles to access supervisor functions.
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

        // Super Admin bypasses all checks
        if ($user->role === 'super_admin') {
            return $next($request);
        }

        // Normalize role aliases (organization <=> admin <=> owner)
        $userRole = $user->role;
        $effectiveRoles = [$userRole];
        if (in_array($userRole, ['organization', 'admin', 'administrator', 'owner', 'organization_owner'])) {
            $effectiveRoles = array_unique(array_merge($effectiveRoles, ['organization', 'admin', 'administrator', 'owner', 'organization_owner']));
        }

        if (empty(array_intersect($effectiveRoles, $roles))) {
            return response()->json([
                'status' => 'error',
                'message' => 'Forbidden. Your role [' . $user->role . '] does not have access to this resource.',
            ], 403);
        }

        return $next($request);
    }
}
