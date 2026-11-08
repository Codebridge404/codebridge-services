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
            if (!$user->tenant_id) {
                $this->recordTenantDenied($request, $user, 'User has no active tenant context assigned');
                abort(403, 'An active workspace membership is required.');
            }

            $isMember = DB::table('tenant_users')
                ->where('tenant_id', $user->tenant_id)
                ->where('user_id', $user->id)
                ->where('status', 'active')
                ->exists();
            if (!$isMember) {
                $this->recordTenantDenied($request, $user, 'User is not an active member of this workspace');
                abort(403, 'An active workspace membership is required.');
            }

            $tenant = DB::table('tenants')->where('id', $user->tenant_id)->first();
            if (!$tenant || !in_array($tenant->status, ['active', 'trial'], true)) {
                $this->recordTenantDenied($request, $user, 'Workspace tenant is suspended or inactive');
                abort(403, 'Workspace tenant is suspended or inactive.');
            }
        }

        return $next($request);
    }

    /**
     * Record tenant access denial security event.
     */
    protected function recordTenantDenied(Request $request, $user, string $reason): void
    {
        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('security_events')) {
                \App\Models\SecurityEvent::record(
                    eventType: 'tenant_access_denied',
                    ruleCode: 'SEC_TENANT_ACCESS_DENIED',
                    summary: "Tenant access denied: {$reason}",
                    severity: 'medium',
                    outcome: 'blocked',
                    confidence: 'confirmed',
                    userId: $user->id,
                    tenantId: $user->tenant_id,
                    request: $request,
                    statusCode: 403
                );
            }
        } catch (\Throwable) {
            // Safe fall-through
        }
    }
}
