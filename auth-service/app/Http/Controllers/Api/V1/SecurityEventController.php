<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\SecurityEvent\SecurityEventResource;
use App\Models\SecurityEvent;
use App\Services\UserAuthorizationService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * [BD-0062] Security Events Forensic Audit & Incident Inspection API
 *
 * Provides administrative oversight into security incidents, rate limiting triggers,
 * token violations, and access denials across CodeBridge services.
 */
class SecurityEventController extends Controller
{
    /**
     * List paginated security events with forensic filters.
     * GET /api/v1/security-events
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SecurityEvent::class);

        $user = $request->user();
        $isSuperAdmin = UserAuthorizationService::isSuperAdmin($user);

        $query = SecurityEvent::with(['user:id,name,email', 'tenant:id,name']);

        // Strict multi-tenant isolation: non-super-admins can only view their tenant
        if (! $isSuperAdmin) {
            $tenantId = $user?->tenant_id;
            if ($tenantId) {
                $query->where('tenant_id', $tenantId);
            } else {
                $query->where('user_id', $user?->id);
            }
        } elseif ($request->filled('tenant_id')) {
            $query->where('tenant_id', $request->integer('tenant_id'));
        }

        // Filter by Severity (low, medium, high, critical)
        if ($request->filled('severity')) {
            $query->where('severity', $request->input('severity'));
        }

        // Filter by Event Type
        if ($request->filled('event_type')) {
            $query->where('event_type', $request->input('event_type'));
        }

        // Filter by Outcome (blocked, allowed, etc.)
        if ($request->filled('outcome')) {
            $query->where('outcome', $request->input('outcome'));
        }

        // Filter by Rule Code
        if ($request->filled('rule_code')) {
            $query->where('rule_code', $request->input('rule_code'));
        }

        // Filter by HTTP Status Code
        if ($request->filled('status_code')) {
            $query->where('status_code', $request->integer('status_code'));
        }

        // Date range filter
        if ($request->filled('from_date') || $request->filled('from')) {
            $from = $request->input('from_date') ?: $request->input('from');
            $query->where('occurred_at', '>=', Carbon::parse($from)->startOfDay());
        }
        if ($request->filled('to_date') || $request->filled('to')) {
            $to = $request->input('to_date') ?: $request->input('to');
            $query->where('occurred_at', '<=', Carbon::parse($to)->endOfDay());
        }

        // Full-text search
        if ($request->filled('search')) {
            $search = '%' . trim($request->input('search')) . '%';
            $query->where(function ($q) use ($search) {
                $q->where('summary', 'like', $search)
                    ->orWhere('ip_address', 'like', $search)
                    ->orWhere('route_template', 'like', $search)
                    ->orWhere('request_id', 'like', $search)
                    ->orWhere('rule_code', 'like', $search);
            });
        }

        $perPage = min(max($request->integer('per_page', 25), 1), 100);
        $paginator = $query->orderBy('occurred_at', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data' => SecurityEventResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'message' => 'Security events retrieved successfully.',
        ]);
    }

    /**
     * Get aggregate security metrics and incident breakdown.
     * GET /api/v1/security-events/stats
     */
    public function stats(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SecurityEvent::class);

        $user = $request->user();
        $isSuperAdmin = UserAuthorizationService::isSuperAdmin($user);

        $baseQuery = DB::table('security_events');

        if (! $isSuperAdmin) {
            $tenantId = $user?->tenant_id;
            if ($tenantId) {
                $baseQuery->where('tenant_id', $tenantId);
            } else {
                $baseQuery->where('user_id', $user?->id);
            }
        } elseif ($request->filled('tenant_id')) {
            $baseQuery->where('tenant_id', $request->integer('tenant_id'));
        }

        $totalEvents = (clone $baseQuery)->count();

        // Count by severity
        $bySeverity = (clone $baseQuery)
            ->select('severity', DB::raw('count(*) as count'))
            ->groupBy('severity')
            ->pluck('count', 'severity')
            ->toArray();

        // Count by outcome
        $byOutcome = (clone $baseQuery)
            ->select('outcome', DB::raw('count(*) as count'))
            ->groupBy('outcome')
            ->pluck('count', 'outcome')
            ->toArray();

        // Top 5 event types
        $topEventTypes = (clone $baseQuery)
            ->select('event_type', DB::raw('count(*) as count'))
            ->groupBy('event_type')
            ->orderByDesc('count')
            ->limit(5)
            ->pluck('count', 'event_type')
            ->toArray();

        // Recent 24 hours incidents
        $recent24h = (clone $baseQuery)
            ->where('occurred_at', '>=', Carbon::now()->subDay())
            ->count();

        return response()->json([
            'status' => 'success',
            'data' => [
                'total_events' => $totalEvents,
                'recent_24h_count' => $recent24h,
                'by_severity' => $bySeverity,
                'by_outcome' => $byOutcome,
                'top_event_types' => $topEventTypes,
            ],
            'message' => 'Security event statistics retrieved successfully.',
        ]);
    }

    /**
     * Inspect a specific security event for forensic investigation.
     * GET /api/v1/security-events/{id}
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $isSuperAdmin = UserAuthorizationService::isSuperAdmin($user);

        $query = SecurityEvent::with(['user:id,name,email', 'tenant:id,name']);

        if (is_numeric($id)) {
            $query->where('id', (int) $id);
        } else {
            $query->where('uuid', $id);
        }

        // Multi-tenant check
        if (! $isSuperAdmin) {
            $tenantId = $user?->tenant_id;
            if ($tenantId) {
                $query->where('tenant_id', $tenantId);
            } else {
                $query->where('user_id', $user?->id);
            }
        }

        $event = $query->first();

        if (! $event) {
            return response()->json([
                'status' => 'error',
                'code' => 'NOT_FOUND',
                'message' => 'Security event not found or access denied.',
            ], 404);
        }

        $this->authorize('view', $event);

        return response()->json([
            'status' => 'success',
            'data' => new SecurityEventResource($event),
            'message' => 'Security event details retrieved successfully.',
        ]);
    }

    /**
     * Get automated threat defense & IP auto-ban configuration.
     * GET /api/v1/security-events/settings
     */
    public function settings(): JsonResponse
    {
        $this->authorize('manageSettings', SecurityEvent::class);

        $settings = \App\Services\IpThreatDefenseService::getSettings();
        $banned = \App\Services\IpThreatDefenseService::getBannedIps();

        return response()->json([
            'status' => 'success',
            'data' => array_merge($settings, [
                'active_banned_ips_count' => count($banned),
            ]),
            'message' => 'Threat defense settings retrieved successfully.',
        ]);
    }

    /**
     * Update automated threat defense settings.
     * PUT /api/v1/security-events/settings
     */
    public function updateSettings(Request $request): JsonResponse
    {
        $this->authorize('manageSettings', SecurityEvent::class);

        $validated = $request->validate([
            'enabled' => 'nullable|boolean',
            'max_offenses' => 'nullable|integer|min:1|max:50',
            'offense_decay_minutes' => 'nullable|integer|min:1|max:1440',
            'ban_duration_minutes' => 'nullable|integer|min:1|max:10080',
        ]);

        $updated = \App\Services\IpThreatDefenseService::updateSettings($validated);

        return response()->json([
            'status' => 'success',
            'data' => $updated,
            'message' => 'Threat defense settings updated successfully.',
        ]);
    }

    /**
     * List all currently banned attacker IPs.
     * GET /api/v1/security-events/banned-ips
     */
    public function bannedIps(): JsonResponse
    {
        $this->authorize('viewBannedIps', SecurityEvent::class);

        $banned = \App\Services\IpThreatDefenseService::getBannedIps();

        return response()->json([
            'status' => 'success',
            'data' => $banned,
            'total' => count($banned),
            'message' => 'Banned IPs retrieved successfully.',
        ]);
    }

    /**
     * Manually ban a malicious IP address.
     * POST /api/v1/security-events/ban-ip
     */
    public function banIp(Request $request): JsonResponse
    {
        $this->authorize('banIp', SecurityEvent::class);

        $validated = $request->validate([
            'ip' => 'required|ip',
            'duration_minutes' => 'nullable|integer|min:1|max:10080',
            'reason' => 'nullable|string|max:255',
        ]);

        $ip = $validated['ip'];
        $duration = $validated['duration_minutes'] ?? 60;
        $reason = $validated['reason'] ?? 'Manual ban by security administrator';

        $banInfo = \App\Services\IpThreatDefenseService::banIp(
            ip: $ip,
            durationMinutes: $duration,
            reason: $reason,
            threatType: 'manual_admin_ban',
            request: $request
        );

        return response()->json([
            'status' => 'success',
            'data' => $banInfo,
            'message' => "IP address '{$ip}' has been banned for {$duration} minutes.",
        ], 201);
    }

    /**
     * Manually unban an IP address.
     * POST /api/v1/security-events/unban-ip
     */
    public function unbanIp(Request $request): JsonResponse
    {
        $this->authorize('unbanIp', SecurityEvent::class);

        $validated = $request->validate([
            'ip' => 'required|ip',
        ]);

        $ip = $validated['ip'];
        \App\Services\IpThreatDefenseService::unbanIp($ip);

        return response()->json([
            'status' => 'success',
            'message' => "IP address '{$ip}' has been unbanned successfully.",
        ]);
    }
}

