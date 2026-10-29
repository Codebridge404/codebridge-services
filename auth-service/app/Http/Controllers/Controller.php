<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Models\AuditLog;

abstract class Controller
{
    use ApiResponse;

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
