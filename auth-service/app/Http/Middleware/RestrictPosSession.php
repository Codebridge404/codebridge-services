<?php

namespace App\Http\Middleware;

use App\Support\SchemaGuard;
use Closure;
use Illuminate\Http\Request;

class RestrictPosSession
{
    public function handle(Request $request, Closure $next)
    {
        $token = $request->user()?->currentAccessToken();
        $abilities = is_array($token?->abilities ?? null) ? $token->abilities : [];
        $isPos = $request->attributes->get('is_pos_session', false)
            || (in_array('pos:access', $abilities, true) && !in_array('*', $abilities, true))
            || ($token && method_exists($token, 'can') && $token->can('pos:access') && !$token->can('*'));
        if ($isPos) {
            // POS sessions may identify the cashier, end the session, or authorize a supervisor PIN.
            // Administrative, workspace, and account-security APIs require full authentication.
            $posLookup = $request->isMethod('GET') && in_array($request->path(), ['api/v1/customers', 'api/v1/outlets', 'api/v1/auth/authorize-context'], true);
            $isAllowed = $posLookup || in_array($request->path(), [
                'api/v1/me', 'api/v1/auth/logout', 'api/v1/auth/verify-pin',
            ], true);

            if (!$isAllowed) {
                try {
                    if (SchemaGuard::hasTable('security_events')) {
                        \App\Models\SecurityEvent::record(
                            eventType: 'pos_scope_violation',
                            ruleCode: 'SEC_POS_SCOPE_VIOLATION',
                            summary: 'POS session attempted to access restricted administrative endpoint',
                            severity: 'medium',
                            outcome: 'blocked',
                            confidence: 'confirmed',
                            userId: $request->user()?->id,
                            tenantId: $request->user()?->tenant_id,
                            request: $request,
                            statusCode: 403
                        );
                    }
                } catch (\Throwable) {}

                abort(403, 'This endpoint requires a full account session.');
            }
        }
        return $next($request);
    }
}
