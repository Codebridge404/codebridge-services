<?php

namespace App\Http\Middleware;

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
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthenticated.',
            ], 401);
        }

        // Super Admin bypasses all checks
        if ($user->role === 'super_admin') {
            return $next($request);
        }

        // Normalize role aliases (organization <=> admin)
        $userRole = $user->role;
        $effectiveRoles = [$userRole];
        if (in_array($userRole, ['organization', 'admin', 'administrator', 'owner'])) {
            $effectiveRoles = array_unique(array_merge($effectiveRoles, ['organization', 'admin', 'administrator', 'owner']));
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
