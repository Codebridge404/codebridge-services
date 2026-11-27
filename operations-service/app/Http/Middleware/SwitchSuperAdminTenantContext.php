<?php

namespace App\Http\Middleware;

use App\Services\TenantAuthorizationService;
use App\Support\SchemaGuard;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Super Admin Global Tenant Context Switcher Middleware (X-Target-Tenant-ID).
 *
 * Allows verified platform super_admin users to scope queries in operations-service
 * to any specific tenant_id while automatically recording an audit_logs entry.
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

        $isPlatformSuperAdmin = ! empty($user->isPlatformSuperAdmin)
            || in_array('super_admin', $user->scopedRoles ?? [], true)
            || ($user->role ?? null) === 'super_admin';

        if (! $isPlatformSuperAdmin) {
            Log::warning('SECURITY EVENT | unauthorized_tenant_switch', [
                'event_type' => 'unauthorized_tenant_switch',
                'rule_code' => 'SEC_FORBIDDEN_TENANT_SWITCH',
                'user_id' => $user->id,
                'tenant_id' => $user->tenant_id,
                'attempted_target_tenant_id' => trim((string) $rawHeader),
                'ip_address' => $request->ip(),
                'request_id' => $request->header('X-Request-ID'),
                'trace_id' => $request->header('X-Trace-ID'),
            ]);

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
        $authConn = TenantAuthorizationService::authConnectionName();
        $authContext = $request->attributes->get('auth_context');
        $authContext = is_array($authContext) ? $authContext : [];

        if (($authContext['source'] ?? null) !== 'auth_http_contract' && SchemaGuard::hasTable('tenants', $authConn)) {
            $tenantRow = $isIntId
                ? DB::connection($authConn)->table('tenants')->where('id', $targetTenantId)->first(['id'])
                : DB::connection($authConn)->table('tenants')->where('uuid', $trimmed)->first(['id']);

            if (! $tenantRow) {
                return response()->json([
                    'status' => 'error',
                    'code' => 'TARGET_TENANT_NOT_FOUND',
                    'message' => "Target tenant '{$trimmed}' does not exist.",
                ], 404);
            }

            $targetTenantId = (int) $tenantRow->id;
        } elseif ($targetTenantId === null) {
            // Remote auth_http_contract mode: numeric tenant_id is resolved on auth_context, or reject unresolvable UUID
            $targetTenantId = is_numeric($authContext['tenant_id'] ?? null) ? (int) $authContext['tenant_id'] : null;
            if ($targetTenantId === null) {
                return response()->json([
                    'status' => 'error',
                    'code' => 'TARGET_TENANT_NOT_FOUND',
                    'message' => "Target tenant '{$trimmed}' could not be resolved.",
                ], 404);
            }
        }

        $originalTenantId = $user->tenant_id ? (int) $user->tenant_id : null;

        $request->attributes->set('original_tenant_id', $originalTenantId);
        $request->attributes->set('auth_tenant_id', $targetTenantId);
        $request->attributes->set('target_tenant_id', $targetTenantId);
        $user->tenant_id = $targetTenantId;
        if (property_exists($user, 'pendingTenantId') || isset($user->pendingTenantId)) {
            $user->pendingTenantId = $targetTenantId;
        }
        $request->attributes->set('auth_context', array_merge($authContext, [
            'tenant_id' => $targetTenantId,
            'requested_tenant_scope' => null,
        ]));

        if (SchemaGuard::hasTable('audit_logs')) {
            try {
                DB::table('audit_logs')->insert([
                    'user_id' => $user->id,
                    'outlet_id' => $user->outlet_id ?? null,
                    'action' => 'super_admin.tenant_context_switched',
                    'auditable_type' => 'tenant',
                    'auditable_id' => (string) $targetTenantId,
                    'old_values' => json_encode(['tenant_id' => $originalTenantId]),
                    'new_values' => json_encode([
                        'tenant_id' => $targetTenantId,
                        'method' => $request->method(),
                        'path' => $request->path(),
                        'request_id' => $request->header('X-Request-ID'),
                        'trace_id' => $request->header('X-Trace-ID'),
                    ]),
                    'ip_address' => $request->ip(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (\Throwable $e) {
                Log::warning('Failed to record super_admin.tenant_context_switched audit log', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $response = $next($request);
        $response->headers->set('X-Active-Tenant-ID', (string) $targetTenantId);

        return $response;
    }
}
