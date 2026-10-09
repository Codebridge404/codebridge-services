<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

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
