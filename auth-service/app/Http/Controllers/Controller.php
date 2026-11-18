<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Models\Security\AuditLog;
use App\Services\UserAuthorizationService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;

abstract class Controller
{
    use ApiResponse, AuthorizesRequests;

    /**
     * Resolve the active tenant ID from authenticated user context or super-admin headers/query.
     */
    protected function getTenantId(Request $request): ?string
    {
        $user = $request->user();
        if ($user && ! UserAuthorizationService::hasPlatformSuperAdminRole($user)) {
            return $user->tenant_id ? (string) $user->tenant_id : null;
        }

        return $request->header('X-Tenant-Id')
            ?? ($user?->tenant_id ? (string) $user->tenant_id : null)
            ?? $request->query('tenant_id')
            ?? null;
    }

    /**
     * Record a business audit log entry from any controller.
     */
    protected function recordAuditLog(
        string $action,
        string $module = 'system',
        ?string $entityType = null,
        ?string $entityId = null,
        ?array $payload = null,
        ?string $details = null,
        ?int $userId = null,
        ?string $userName = null,
        ?int $tenantId = null
    ): ?AuditLog {
        // Mark request as explicitly audited to avoid duplicate generic mutation logging
        request()->attributes->set('_audit_logged', true);

        return AuditLog::record(
            action: $action,
            module: $module,
            userId: $userId,
            userName: $userName,
            tenantId: $tenantId,
            entityType: $entityType,
            entityId: $entityId,
            payload: $payload,
            details: $details
        );
    }
}
