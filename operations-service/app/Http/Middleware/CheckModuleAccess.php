<?php

namespace App\Http\Middleware;

use App\Services\ModuleEntitlementService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cross-Service Module Entitlement & Global Kill-Switch Middleware (Operations Service).
 *
 * Returns 503 MODULE_DISABLED when a module's global kill-switch is active,
 * or 403 MODULE_NOT_SUBSCRIBED when a tenant lacks an active subscription.
 */
class CheckModuleAccess
{
    public function __construct(
        protected ModuleEntitlementService $entitlementService
    ) {}

    public function handle(Request $request, Closure $next, string $module): Response
    {
        $user = $request->user();
        $decision = $this->entitlementService->checkAccess($module, $request, $user);

        if (! $decision['allowed']) {
            $status = (int) ($decision['status'] ?? 403);
            $code = (string) ($decision['code'] ?? 'MODULE_NOT_SUBSCRIBED');
            $tenantId = $user?->tenant_id ?? $request->attributes->get('auth_tenant_id');

            if ($status === 403) {
                Log::warning('SECURITY EVENT | module_access_denied', [
                    'event_type' => 'module_access_denied',
                    'rule_code' => 'SEC_MODULE_NOT_SUBSCRIBED',
                    'module_code' => strtolower(trim($module)),
                    'tenant_id' => $tenantId,
                    'user_id' => $user?->id,
                    'status_code' => $status,
                    'ip_address' => $request->ip(),
                    'request_id' => $request->header('X-Request-ID'),
                    'trace_id' => $request->header('X-Trace-ID'),
                ]);
            }

            return response()->json([
                'status' => 'error',
                'code' => $code,
                'message' => $decision['message'] ?? 'Module access denied.',
            ], $status);
        }

        return $next($request);
    }
}
