<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Security\AuditLogController;
use App\Models\Security\SecurityEvent;
use App\Services\UserAuthorizationService;
use App\Support\SchemaGuard;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Super Admin Global Tenant Context Switcher Middleware (X-Target-Tenant-ID).
 *
 * Allows verified platform super_admin users to scope queries to a specific
 * tenant_id via the X-Target-Tenant-ID header while automatically recording
 * an immutable audit_logs entry and rejecting unauthorized callers with 403.
 */
class SwitchSuperAdminTenantContext
{
    public const HEADER_NAME = 'X-Target-Tenant-ID';

    public function handle(Request $request, Closure $next): Response
    {
        $rawHeader = $request->header(self::HEADER_NAME);
        if ($rawHeader === null || trim((string) $rawHeader) === '') {
            return $next($request);
        }

        $user = $request->user() ?? Auth::guard('sanctum')->user();
        if (! $user) {
            return $next($request);
        }

        if (! UserAuthorizationService::hasPlatformSuperAdminRole($user)) {
            if (class_exists(SecurityEvent::class)) {
                SecurityEvent::record(
                    eventType: 'unauthorized_tenant_switch',
                    ruleCode: 'SEC_FORBIDDEN_TENANT_SWITCH',
                    summary: 'Non-super_admin attempted to use X-Target-Tenant-ID header.',
                    severity: 'medium',
                    outcome: 'blocked',
                    confidence: 1.0,
                    userId: $user->id,
                    tenantId: $user->tenant_id ? (int) $user->tenant_id : null,
                    request: $request,
                    metadata: ['attempted_target_tenant_id' => trim((string) $rawHeader)],
                    statusCode: 403
                );
            }

            return response()->json([
                'status' => 'error',
                'code' => 'FORBIDDEN_TENANT_SWITCH',
                'message' => 'Only platform super_admin may switch tenant context via X-Target-Tenant-ID.',
            ], 403);
        }

        $trimmed = trim((string) $rawHeader);
        $isIntId = ctype_digit($trimmed) && (int) $trimmed > 0;
        $isUuid = Str::isUuid($trimmed);

        if (! $isIntId && ! $isUuid) {
            return response()->json([
                'status' => 'error',
                'code' => 'INVALID_TARGET_TENANT_ID',
                'message' => 'X-Target-Tenant-ID must be a positive integer or valid UUID.',
            ], 422);
        }

        $targetTenantId = $isIntId ? (int) $trimmed : null;

        if (SchemaGuard::hasTable('tenants')) {
            $tenantRow = $isIntId
                ? DB::table('tenants')->where('id', $targetTenantId)->first(['id'])
                : DB::table('tenants')->where('uuid', $trimmed)->first(['id']);

            if (! $tenantRow) {
                return response()->json([
                    'status' => 'error',
                    'code' => 'TARGET_TENANT_NOT_FOUND',
                    'message' => "Target tenant '{$trimmed}' does not exist.",
                ], 404);
            }

            $targetTenantId = (int) $tenantRow->id;
        } elseif ($targetTenantId === null) {
            return response()->json([
                'status' => 'error',
                'code' => 'TARGET_TENANT_NOT_FOUND',
                'message' => "Target tenant '{$trimmed}' could not be resolved.",
            ], 404);
        }

        $originalTenantId = $user->tenant_id ? (int) $user->tenant_id : null;

        $request->attributes->set('original_tenant_id', $originalTenantId);
        $request->attributes->set('auth_tenant_id', $targetTenantId);
        $request->attributes->set('target_tenant_id', $targetTenantId);
        $user->tenant_id = $targetTenantId;

        if (class_exists(AuditLogController::class)) {
            AuditLogController::log(
                $user->id,
                $user->name ?? 'super_admin',
                'super_admin.tenant_context_switched',
                'super_admin',
                $request->ip(),
                [
                    'original_tenant_id' => $originalTenantId,
                    'target_tenant_id' => $targetTenantId,
                    'method' => $request->method(),
                    'path' => $request->path(),
                    'request_id' => $request->attributes->get('request_id') ?? $request->header('X-Request-ID'),
                    'trace_id' => $request->attributes->get('trace_id') ?? $request->header('X-Trace-ID'),
                ],
                $targetTenantId
            );
        }

        $response = $next($request);
        $response->headers->set('X-Active-Tenant-ID', (string) $targetTenantId);

        return $response;
    }
}
