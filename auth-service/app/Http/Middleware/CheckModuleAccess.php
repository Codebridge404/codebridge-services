<?php

namespace App\Http\Middleware;

use App\Services\ModuleEntitlementService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Redis-cached Module Entitlement & Global Kill-Switch Middleware.
 *
 * Usage: Route::middleware('module:crm')
 * Returns 503 MODULE_DISABLED on global kill-switch and 403 MODULE_NOT_SUBSCRIBED
 * when the authenticated tenant lacks an active subscription for the module.
 */
class CheckModuleAccess
{
    public function __construct(
        protected ModuleEntitlementService $entitlementService
    ) {}

    public function handle(Request $request, Closure $next, string $module): Response
    {
        $user = $request->user();
        $tenantId = $request->attributes->get('auth_tenant_id')
            ?? $user?->tenant_id;

        $check = $this->entitlementService->checkAccess(
            moduleId: $module,
            tenantId: is_numeric($tenantId) ? (int) $tenantId : null,
            user: $user
        );

        if (! $check['allowed']) {
            return response()->json([
                'status' => 'error',
                'code' => $check['code'] ?? 'MODULE_ACCESS_DENIED',
                'message' => $check['message'] ?? 'Access denied for this module.',
                'module' => strtolower(trim($module)),
            ], (int) ($check['status'] ?? 403));
        }

        return $next($request);
    }
}
