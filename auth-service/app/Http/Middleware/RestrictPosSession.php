<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RestrictPosSession
{
    public function handle(Request $request, Closure $next)
    {
        $token = $request->user()?->currentAccessToken();
        $abilities = $token?->abilities ?? [];
        $isPos = $request->attributes->get('is_pos_session', false)
            || (in_array('pos:access', $abilities, true) && !in_array('*', $abilities, true));
        if ($isPos) {
            // POS sessions may identify the cashier, end the session, or authorize a supervisor PIN.
            // Administrative, workspace, and account-security APIs require full authentication.
            $posLookup = $request->isMethod('GET') && in_array($request->path(), ['api/v1/customers', 'api/v1/outlets'], true);
            abort_unless($posLookup || in_array($request->path(), [
                'api/v1/me', 'api/v1/auth/logout', 'api/v1/auth/verify-pin',
            ], true), 403, 'This endpoint requires a full account session.');
        }
        return $next($request);
    }
}
