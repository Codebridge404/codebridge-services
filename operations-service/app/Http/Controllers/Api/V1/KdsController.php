<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class KdsController extends Controller
{
    /**
     * Get active kitchen display tickets
     */
    public function index(Request $request)
    {
        $this->ensureSampleTicketsSeeded();

        $tickets = DB::table('kds_tickets')
            ->whereIn('status', ['pending', 'preparing', 'ready'])
            ->orderBy('created_at', 'asc')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $tickets,
        ]);
    }

    /**
     * Update KDS Ticket Status (Preparing / Ready / Bumped)
     */
    public function updateStatus(Request $request, $id)
    {
        $validated = $request->validate([
            'status' => 'required|in:pending,preparing,ready,bumped',
        ]);

        DB::table('kds_tickets')
            ->where('id', $id)
            ->update([
                'status' => $validated['status'],
                'updated_at' => now(),
            ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Kitchen ticket status updated.',
        ]);
    }

    protected function ensureSampleTicketsSeeded()
    {
        if (DB::table('kds_tickets')->count() === 0) {
            DB::table('kds_tickets')->insert([
                [
                    'ticket_number' => 'KDS-0881',
                    'order_type' => 'dine_in',
                    'table_name' => 'Table #03',
                    'items' => json_encode([
                        ['name' => 'Iced Americano', 'quantity' => 2, 'note' => 'Less ice, 50% sugar'],
                        ['name' => 'Butter Croissant', 'quantity' => 1, 'note' => 'Warmed up'],
                    ]),
                    'status' => 'preparing',
                    'prep_time_minutes' => 4,
                    'created_at' => now()->subMinutes(4),
                    'updated_at' => now(),
                ],
                [
                    'ticket_number' => 'KDS-0882',
                    'order_type' => 'takeaway',
                    'table_name' => 'Takeaway #12',
                    'items' => json_encode([
                        ['name' => 'Matcha Green Tea Latte', 'quantity' => 1, 'note' => 'Oat milk'],
                        ['name' => 'Chocolate Muffin', 'quantity' => 2, 'note' => ''],
                    ]),
                    'status' => 'pending',
                    'prep_time_minutes' => 1,
                    'created_at' => now()->subMinute(),
                    'updated_at' => now(),
                ],
            ]);
        }
    }
}
