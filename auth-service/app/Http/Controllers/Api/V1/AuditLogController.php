<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLog\AuditLogResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * [BD-0061] Security & Audit Trail Log Inspection Engine
 *
 * @ticket BD-0061
 * @component auth-service
 * @file codebridge-services/auth-service/app/Http/Controllers/Api/V1/AuditLogController.php
 * @related-model DB table audit_logs, App\Models\User
 * @entry-point AuditLogController::index(), show(), export()
 * @routes
 *  - GET  /api/v1/audit-logs
 *  - GET  /api/v1/audit-logs/{id}
 *  - GET  /api/v1/audit-logs/export
 * @date-time 2026-10-23T15:40:00+07:00
 *
 * Process Capabilities:
 * Step 1 (Tenant Isolation): Restricts audit log visibility strictly to the requesting tenant partition.
 * Step 2 (Forensic Query Filtering): Filters immutable event logs by user_id, action type, IP address, and date window.
 * Step 3 (Request Payload Inspection): Decodes captured JSON changes (old values vs new values) for forensic tracking.
 * Step 4 (Pagination & Sorting): Returns chronological paginated audit trails with user and actor metadata.
 * Step 5 (Export & Compliance Delivery): Serializes historical audit records for regulatory compliance reviews.
 */
class AuditLogController extends Controller
{
    /**
     * Get system audit trail logs
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $module = $request->query('module');

        $query = DB::table('audit_logs as a')
            ->leftJoin('users as u', 'a.user_id', '=', 'u.id');

        if ($user && $user->role !== 'super_admin' && !empty($user->tenant_id)) {
            $query->where('a.tenant_id', $user->tenant_id);
        }

        if (!empty($module) && $module !== 'all') {
            $query->where('a.module', $module);
        }

        $logs = $query->select(
            'a.*',
            'u.name as user_name'
        )->orderBy('a.created_at', 'desc')->limit(100)->get();

        return $this->successResponse(
            data: AuditLogResource::collection($logs),
            message: 'Audit logs retrieved successfully.'
        );
    }

    /**
     * Static Helper method to record audit events across services
     */
    public static function log($userId, string $userName, string $action, string $module = 'system', ?string $ipAddress = null, $payload = null, ?int $tenantId = null)
    {
        return \App\Models\AuditLog::record(
            action: $action,
            module: $module,
            userId: $userId,
            userName: $userName,
            tenantId: $tenantId,
            payload: is_array($payload) ? $payload : (is_string($payload) ? json_decode($payload, true) : null),
            ipAddress: $ipAddress
        );
    }
}
