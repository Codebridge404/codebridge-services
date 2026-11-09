<?php

namespace App\Http\Middleware;

use App\Services\UserAuthorizationService;
use Closure;
use Illuminate\Http\Request;

class EnsureWorkspace
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        abort_unless($user, 401);

        if ($request->attributes->get('jwt_tenant_id_invalid')) {
            $this->recordTenantDenied($request, $user, 'Token contains an invalid tenant identifier');
            abort(403, 'Invalid tenant context.');
        }

        $claimTenantId = $request->attributes->get('jwt_tenant_id');
        $context = UserAuthorizationService::resolveAuthorizationContext(
            $user,
            null,
            is_numeric($claimTenantId) ? (int) $claimTenantId : null,
            true
        );

        if (!$context['authorized']) {
            if (($context['status'] ?? 403) === 401) {
                return response()->json([
                    'status' => 'error',
                    'code' => $context['code'] ?? 'UNAUTHENTICATED',
                    'message' => $context['message'] ?? 'Unauthenticated.',
                ], 401);
            }

            $this->recordTenantDenied(
                $request,
                $user,
                $context['message'] ?? 'An active workspace membership is required.',
                $context['tenant_id'] ?? $user->tenant_id
            );
            abort((int) ($context['status'] ?? 403), (string) ($context['message'] ?? 'An active workspace membership is required.'));
        }

        $request->attributes->set('auth_context', $context);

        return $next($request);
    }

    /**
     * Record tenant access denial security event.
     */
    protected function recordTenantDenied(Request $request, $user, string $reason, ?int $tenantId = null): void
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
                    tenantId: $tenantId ?? $user->tenant_id,
                    request: $request,
                    statusCode: 403
                );
            }
        } catch (\Throwable) {
            // Safe fall-through
        }
    }
}
