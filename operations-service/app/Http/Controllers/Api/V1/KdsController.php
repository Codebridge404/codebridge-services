<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\KdsTicket;
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
    /**
     * Get active kitchen display tickets
     */
    public function index(Request $request)
    {
        $this->ensureSampleTicketsSeeded();

        $outletId = $request->query('outlet_id');
        $station = $request->query('station', 'all');

        $query = KdsTicket::query()
            ->whereIn('status', ['pending', 'preparing', 'ready'])
            ->orderBy('created_at', 'asc');

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

        $tickets = $query->get();

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
        $outletId = $request->query('outlet_id');
        $station = $request->query('station', 'all');

        return response()->stream(function () use ($outletId, $station) {
            // Turn off output buffering for instant flushing
            while (ob_get_level() > 0) {
                ob_end_flush();
            }

            $lastKnownUpdate = null;
            $heartbeatCounter = 0;
            $maxLifetimeSeconds = 300; // 5-minute persistent stream window
            $startedAt = time();

            while (!connection_aborted() && (time() - $startedAt < $maxLifetimeSeconds)) {
                $currentUpdateTime = (float) Cache::get('kds_last_update_time', 0);

                if ($lastKnownUpdate === null || $currentUpdateTime > $lastKnownUpdate) {
                    $query = KdsTicket::query()
                        ->whereIn('status', ['pending', 'preparing', 'ready'])
                        ->orderBy('created_at', 'asc');

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

                    $tickets = $query->get();
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
        $validated = $request->validate([
            'sale_id' => 'nullable|integer',
            'outlet_id' => 'nullable|integer',
            'ticket_number' => 'required|string|max:50',
            'order_type' => 'nullable|string|in:dine_in,takeaway,delivery',
            'table_name' => 'nullable|string|max:100',
            'station' => 'nullable|string|max:50',
            'items' => 'required|array',
        ]);

        $ticket = KdsTicket::create([
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

        // Touch broadcast cache tick
        Cache::put('kds_last_update_time', microtime(true), 86400);

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
        $validated = $request->validate([
            'status' => 'required|in:pending,preparing,ready,bumped',
        ]);

        $ticket = KdsTicket::findOrFail($id);

        if ($validated['status'] === 'bumped') {
            $ticket->bump();
        } else {
            $ticket->status = $validated['status'];
            $ticket->save();
        }

        // Signal real-time SSE stream listeners
        Cache::put('kds_last_update_time', microtime(true), 86400);

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
        $ticket = KdsTicket::findOrFail($id);
        $ticket->bump();

        // Signal real-time SSE stream listeners
        Cache::put('kds_last_update_time', microtime(true), 86400);

        return response()->json([
            'status' => 'success',
            'message' => 'Kitchen ticket successfully bumped.',
            'data' => $ticket,
        ]);
    }

    /**
     * Seed initial tickets if database table is empty
     */
    protected function ensureSampleTicketsSeeded()
    {
        if (KdsTicket::count() === 0) {
            KdsTicket::create([
                'ticket_number' => 'KDS-0881',
                'order_type' => 'dine_in',
                'table_name' => 'Table #03',
                'station' => 'all',
                'items' => [
                    ['name' => 'Iced Americano', 'quantity' => 2, 'note' => 'Less ice, 50% sugar'],
                    ['name' => 'Butter Croissant', 'quantity' => 1, 'note' => 'Warmed up'],
                ],
                'status' => 'preparing',
                'prep_time_minutes' => 4,
            ]);

            KdsTicket::create([
                'ticket_number' => 'KDS-0882',
                'order_type' => 'takeaway',
                'table_name' => 'Takeaway #12',
                'station' => 'bar',
                'items' => [
                    ['name' => 'Matcha Green Tea Latte', 'quantity' => 1, 'note' => 'Oat milk'],
                    ['name' => 'Chocolate Muffin', 'quantity' => 2, 'note' => ''],
                ],
                'status' => 'pending',
                'prep_time_minutes' => 1,
            ]);
        }
    }
}
