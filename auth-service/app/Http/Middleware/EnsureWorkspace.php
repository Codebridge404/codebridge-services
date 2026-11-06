<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EnsureWorkspace
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        abort_unless($user, 401);

        if (! $user->is_active) {
            return response()->json([
                'status' => 'error',
                'code' => 'ACCOUNT_DEACTIVATED',
                'message' => 'User account is deactivated.',
            ], 401);
        }

        if (method_exists($user, 'isLockedOut') && $user->isLockedOut()) {
            return response()->json([
                'status' => 'error',
                'code' => 'ACCOUNT_LOCKED_OUT',
                'message' => 'User account is temporarily locked out.',
            ], 401);
        }

        if ($user->role !== 'super_admin') {
            abort_unless($user->tenant_id, 403, 'An active workspace membership is required.');

            $isMember = DB::table('tenant_users')
                ->where('tenant_id', $user->tenant_id)
                ->where('user_id', $user->id)
                ->where('status', 'active')
                ->exists();
            abort_unless($isMember, 403, 'An active workspace membership is required.');

            $tenant = DB::table('tenants')->where('id', $user->tenant_id)->first();
            abort_unless($tenant && in_array($tenant->status, ['active', 'trial'], true), 403, 'Workspace tenant is suspended or inactive.');
        }

        return $next($request);
    }
}
