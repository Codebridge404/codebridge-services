<?php

namespace App\Http\Middleware;

use App\Services\TenantAccess;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EnsureWorkspace
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        abort_unless($user, 401);
        if ($user->role !== 'super_admin') {
            abort_unless($user->tenant_id && DB::table('tenant_users')
                ->where('tenant_id', $user->tenant_id)->where('user_id', $user->id)
                ->where('status', 'active')->exists(), 403, 'An active workspace membership is required.');
        }
        return $next($request);
    }
}
