<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\KdsTicket;
use App\Services\TenantAuthorizationService;
use App\Traits\ResolvesTenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * [BD-0097] Kitchen Display System Kitchen Ticket Routing & Cooking Status Controller
 *
 * @ticket BD-0097
 * @component operations-service
 * @file codebridge-services/operations-service/app/Http/Controllers/Api/V1/KdsController.php
 * @related-model App\Models\KdsTicket, App\Models\Sale
 * @entry-point KdsController::index(), stream(), updateStatus(), bumpTicket(), store()
 * @routes
 *  - GET  /api/v1/kds/tickets
 *  - GET  /api/v1/kds/stream
 *  - POST /api/v1/kds/tickets
 *  - PATCH /api/v1/kds/tickets/{id}/status
 *  - POST /api/v1/kds/tickets/{id}/bump
 * @date-time 2026-10-26T10:40:00+07:00
 *
 * Process Capabilities:
 * Step 1 (Station Routing): Routes order line items to specific kitchen prep stations (Grill, Bar, Salad, Kitchen).
 * Step 2 (Real-Time SSE Stream): High-performance Server-Sent Events push feed of incoming tickets without client polling.
 * Step 3 (Preparation State Machine): Transitions item status through pending -> preparing -> ready -> bumped.
 * Step 4 (Ticket Timing & Latency): Accurately logs prep latency in minutes and records completion timestamp.
 * Step 5 (Kitchen Item Bump): Bumps completed orders from display and signals waiters/runners.
 */
class KdsController extends Controller
{
    use ResolvesTenantContext;

    private function kdsCacheKey(?string $tenantId, $outletId = null): string
    {
        $tenantPart = ($tenantId && $tenantId !== 'all') ? $tenantId : 'global';
        $outletPart = $outletId ? "_outlet_{$outletId}" : '';

        return "kds_last_update_time_tenant_{$tenantPart}{$outletPart}";
    }

    private function assertOutletBelongsToTenant($outletId, string $tenantId, bool $isSuperAdminAll): void
    {
        if (empty($outletId) || $isSuperAdminAll) {
            return;
        }
        $outlet = DB::table('outlets')->where('id', $outletId)->first();
        if ($outlet && $outlet->tenant_id !== null && (string) $outlet->tenant_id !== (string) $tenantId) {
            abort(403, 'Unauthorized: Outlet belongs to another organization.');
        }
    }

    /**
     * Get active kitchen display tickets
     */
    public function index(Request $request)
    {
        $tenantId = $this->getTenantId($request, true);
        $isSuperAdminAll = ($tenantId === 'all' && TenantAuthorizationService::hasPlatformSuperAdminRole($request->user()));

        $outletId = $request->query('outlet_id');
        $this->assertOutletBelongsToTenant($outletId, $tenantId, $isSuperAdminAll);
        $station = $request->query('station', 'all');

        $query = KdsTicket::query()
            ->whereIn('status', ['pending', 'preparing', 'ready'])
            ->orderBy('created_at', 'asc');

        if (!$isSuperAdminAll) {
            $query->where('tenant_id', $tenantId);
        }

        if ($outletId) {
            $query->where(function ($q) use ($outletId) {
                $q->where('outlet_id', $outletId)->orWhereNull('outlet_id');
            });
        }

        if ($station && $station !== 'all') {
            $query->where(function ($q) use ($station) {
                $q->where('station', $station)->orWhere('station', 'all');
            });
        }

        $tickets = $query->limit(100)->get();

        return response()->json([
            'status' => 'success',
            'data' => $tickets,
        ]);
    }

    /**
     * Real-Time Server-Sent Events (SSE) Broadcaster for Kitchen Display Monitors
     */
    public function stream(Request $request): StreamedResponse
    {
        $tenantId = $this->getTenantId($request, true);
        $isSuperAdminAll = ($tenantId === 'all' && TenantAuthorizationService::hasPlatformSuperAdminRole($request->user()));

        $outletId = $request->query('outlet_id');
        $this->assertOutletBelongsToTenant($outletId, $tenantId, $isSuperAdminAll);
        $station = $request->query('station', 'all');
        $cacheKey = $this->kdsCacheKey($tenantId, $outletId);

        return response()->stream(function () use ($tenantId, $isSuperAdminAll, $outletId, $station, $cacheKey) {
            // Turn off output buffering for instant flushing
            while (ob_get_level() > 0) {
                ob_end_flush();
            }

            $lastKnownUpdate = null;
            $heartbeatCounter = 0;
            $maxLifetimeSeconds = 300; // 5-minute persistent stream window
            $startedAt = time();

            while (!connection_aborted() && (time() - $startedAt < $maxLifetimeSeconds)) {
                $currentUpdateTime = (float) Cache::get($cacheKey, (float) Cache::get($this->kdsCacheKey($tenantId), 0));

                if ($lastKnownUpdate === null || $currentUpdateTime > $lastKnownUpdate) {
                    $query = KdsTicket::query()
                        ->whereIn('status', ['pending', 'preparing', 'ready'])
                        ->orderBy('created_at', 'asc');

                    if (!$isSuperAdminAll) {
                        $query->where('tenant_id', $tenantId);
                    }

                    if ($outletId) {
                        $query->where(function ($q) use ($outletId) {
                            $q->where('outlet_id', $outletId)->orWhereNull('outlet_id');
                        });
                    }

                    if ($station && $station !== 'all') {
                        $query->where(function ($q) use ($station) {
                            $q->where('station', $station)->orWhere('station', 'all');
                        });
                    }

                    $tickets = $query->limit(100)->get();
                    $lastKnownUpdate = $currentUpdateTime ?: microtime(true);

                    echo "event: kds-update\n";
                    echo "data: " . json_encode([
                        'timestamp' => now()->toIso8601String(),
                        'data' => $tickets,
                    ]) . "\n\n";

                    if (ob_get_level() > 0) ob_flush();
                    flush();
                }

                // Heartbeat ping every 10 seconds
                $heartbeatCounter++;
                if ($heartbeatCounter >= 10) {
                    echo "event: ping\n";
                    echo "data: " . json_encode(['time' => time()]) . "\n\n";
                    if (ob_get_level() > 0) ob_flush();
                    flush();
                    $heartbeatCounter = 0;
                }

                usleep(1000000); // 1.0 second tick
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * Create a new KDS Ticket manually or from an external ordering engine
     */
    public function store(Request $request)
    {
        $tenantId = $this->getTenantId($request, true);
        $writeTenantId = $tenantId === 'all' ? null : $tenantId;
        $isSuperAdminAll = ($tenantId === 'all' && TenantAuthorizationService::hasPlatformSuperAdminRole($request->user()));

        $validated = $request->validate([
            'sale_id' => 'nullable|integer',
            'outlet_id' => 'nullable|integer',
            'ticket_number' => 'required|string|max:50',
            'order_type' => 'nullable|string|in:dine_in,takeaway,delivery',
            'table_name' => 'nullable|string|max:100',
            'station' => 'nullable|string|max:50',
            'items' => 'required|array',
        ]);

        if (!empty($validated['outlet_id'])) {
            $this->assertOutletBelongsToTenant($validated['outlet_id'], $tenantId, $isSuperAdminAll);
        }

        if (!empty($validated['sale_id'])) {
            $sale = DB::table('sales')->where('id', $validated['sale_id'])->first();
            if (!$sale) {
                return response()->json(['status' => 'error', 'message' => 'Sale not found.'], 404);
            }
            $this->authorizeTenantResource($sale->tenant_id, $request, 'Sale');
        }

        $ticket = KdsTicket::create([
            'tenant_id' => $writeTenantId,
            'sale_id' => $validated['sale_id'] ?? null,
            'outlet_id' => $validated['outlet_id'] ?? null,
            'ticket_number' => $validated['ticket_number'],
            'order_type' => $validated['order_type'] ?? 'dine_in',
            'table_name' => $validated['table_name'] ?? 'Order Ticket',
            'station' => $validated['station'] ?? 'all',
            'items' => $validated['items'],
            'status' => 'pending',
            'prep_time_minutes' => 0,
        ]);

        $nowTick = microtime(true);
        Cache::put($this->kdsCacheKey($writeTenantId), $nowTick, 86400);
        if (!empty($validated['outlet_id'])) {
            Cache::put($this->kdsCacheKey($writeTenantId, $validated['outlet_id']), $nowTick, 86400);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'KDS ticket created.',
            'data' => $ticket,
        ], 201);
    }

    /**
     * Update KDS Ticket Status (pending -> preparing -> ready -> bumped)
     */
    public function updateStatus(Request $request, $id)
    {
        $this->getTenantId($request, true);

        $validated = $request->validate([
            'status' => 'required|in:pending,preparing,ready,bumped',
        ]);

        $ticket = KdsTicket::findOrFail($id);
        $this->authorizeTenantResource($ticket->tenant_id, $request, 'KDS ticket');

        if ($validated['status'] === 'bumped') {
            $ticket->bump();
        } else {
            $ticket->status = $validated['status'];
            $ticket->save();
        }

        $ticketTenant = $ticket->tenant_id !== null ? (string) $ticket->tenant_id : null;
        $nowTick = microtime(true);
        Cache::put($this->kdsCacheKey($ticketTenant), $nowTick, 86400);
        if (!empty($ticket->outlet_id)) {
            Cache::put($this->kdsCacheKey($ticketTenant, $ticket->outlet_id), $nowTick, 86400);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Kitchen ticket status updated.',
            'data' => $ticket,
        ]);
    }

    /**
     * Bump completed order ticket directly
     */
    public function bumpTicket(Request $request, $id)
    {
        $this->getTenantId($request, true);

        $ticket = KdsTicket::findOrFail($id);
        $this->authorizeTenantResource($ticket->tenant_id, $request, 'KDS ticket');
        $ticket->bump();

        $ticketTenant = $ticket->tenant_id !== null ? (string) $ticket->tenant_id : null;
        $nowTick = microtime(true);
        Cache::put($this->kdsCacheKey($ticketTenant), $nowTick, 86400);
        if (!empty($ticket->outlet_id)) {
            Cache::put($this->kdsCacheKey($ticketTenant, $ticket->outlet_id), $nowTick, 86400);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Kitchen ticket successfully bumped.',
            'data' => $ticket,
        ]);
    }
}
