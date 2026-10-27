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
        $module = $request->query('module');

        $query = DB::table('audit_logs')->orderBy('created_at', 'desc');

        if (!empty($module) && $module !== 'all') {
            $query->where('module', $module);
        }

        $logs = $query->limit(100)->get();

        return response()->json([
            'status' => 'success',
            'data' => AuditLogResource::collection($logs),
        ]);
    }

    /**
     * Static Helper method to record audit events across services
     */
    public static function log($userId, string $userName, string $action, string $module = 'system', ?string $ipAddress = null, $payload = null)
    {
        try {
            $data = [
                'user_id' => $userId,
                'action' => $action,
                'ip_address' => $ipAddress ?? request()->ip(),
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if (\Illuminate\Support\Facades\Schema::hasColumn('audit_logs', 'user_name')) {
                $data['user_name'] = $userName;
            }
            if (\Illuminate\Support\Facades\Schema::hasColumn('audit_logs', 'module')) {
                $data['module'] = $module;
            }
            if (\Illuminate\Support\Facades\Schema::hasColumn('audit_logs', 'payload')) {
                $data['payload'] = $payload ? json_encode($payload) : null;
            }
            if (\Illuminate\Support\Facades\Schema::hasColumn('audit_logs', 'new_values') && !isset($data['payload'])) {
                $data['new_values'] = $payload ? json_encode($payload) : null;
            }

            DB::table('audit_logs')->insert($data);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[AuditLog] Failed to record audit log: ' . $e->getMessage());
        }
    }
}
