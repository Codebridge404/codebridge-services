<?php

namespace App\Http\Middleware;

use App\Jobs\SendPlatformAlertNotificationJob;
use App\Models\Security\SecurityEvent;
use App\Services\ModuleEntitlementService;
use App\Support\SchemaGuard;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Redis-cached Module Entitlement & Global Kill-Switch Middleware.
 *
 * Usage: Route::middleware('module:crm')
 * Returns 503 MODULE_DISABLED on global kill-switch and 403 MODULE_NOT_SUBSCRIBED
 * when the authenticated tenant lacks an active subscription for the module.
 * Records SecurityEvent on 403 denials and queues threshold alerts.
 */
class CheckModuleAccess
{
    public const DENIAL_ALERT_THRESHOLD = 5;

    public function __construct(
        protected ModuleEntitlementService $entitlementService
    ) {}

    public function handle(Request $request, Closure $next, string $module): Response
    {
        $user = $request->user();
        $tenantId = $request->attributes->get('auth_tenant_id')
            ?? $user?->tenant_id;
        $resolvedTenantId = is_numeric($tenantId) ? (int) $tenantId : null;
        $normalizedModule = strtolower(trim($module));

        $check = $this->entitlementService->checkAccess(
            moduleId: $normalizedModule,
            tenantId: $resolvedTenantId,
            user: $user
        );

        if (! $check['allowed']) {
            $status = (int) ($check['status'] ?? 403);
            $code = (string) ($check['code'] ?? 'MODULE_ACCESS_DENIED');

            if ($status === 403 && $code === 'MODULE_NOT_SUBSCRIBED') {
                $this->recordDenialSecurityEvent($request, $normalizedModule, $resolvedTenantId, $user?->id);
            }

            return response()->json([
                'status' => 'error',
                'code' => $code,
                'message' => $check['message'] ?? 'Access denied for this module.',
                'module' => $normalizedModule,
            ], $status);
        }

        return $next($request);
    }

    protected function recordDenialSecurityEvent(Request $request, string $moduleCode, ?int $tenantId, ?int $userId): void
    {
        $denialCount = 1;
        try {
            $counterKey = 'module_denial_count:'.md5(($userId ?? 'guest').'|'.$request->ip().'|'.$moduleCode);
            if (! Cache::has($counterKey)) {
                Cache::put($counterKey, 1, now()->addMinutes(5));
            } else {
                $denialCount = (int) Cache::increment($counterKey);
            }
        } catch (\Throwable) {
        }

        $severity = $denialCount >= self::DENIAL_ALERT_THRESHOLD ? 'medium' : 'low';

        try {
            if (SchemaGuard::hasTable('security_events')) {
                SecurityEvent::record(
                    eventType: 'module_access_denied',
                    ruleCode: 'SEC_MODULE_NOT_SUBSCRIBED',
                    summary: "Access denied to unsubscribed module '{$moduleCode}'",
                    severity: $severity,
                    outcome: 'blocked',
                    confidence: 'confirmed',
                    userId: $userId,
                    tenantId: $tenantId,
                    request: $request,
                    metadata: [
                        'module_code' => $moduleCode,
                        'reason' => 'MODULE_NOT_SUBSCRIBED',
                        'denial_count_5m' => $denialCount,
                    ],
                    statusCode: 403
                );
            }
        } catch (\Throwable) {
        }

        if ($denialCount >= self::DENIAL_ALERT_THRESHOLD) {
            try {
                $alertThrottleKey = 'module_denial_alert:'.md5(($tenantId ?? '0').'|'.$request->ip().'|'.$moduleCode);
                if (! Cache::has($alertThrottleKey)) {
                    Cache::put($alertThrottleKey, true, now()->addMinutes(10));
                    SendPlatformAlertNotificationJob::dispatch(
                        eventType: 'module.repeated_access_denied',
                        title: 'Repeated Unsubscribed Module Access Blocked',
                        summary: "Module '{$moduleCode}' denied {$denialCount} times in 5m (tenant_id=".(string) ($tenantId ?? 'none').', ip='.$request->ip().').',
                        tenantId: $tenantId,
                        context: [
                            'module_code' => $moduleCode,
                            'tenant_id' => $tenantId,
                            'user_id' => $userId,
                            'denial_count_5m' => $denialCount,
                        ]
                    );
                }
            } catch (\Throwable) {
            }
        }
    }
}
