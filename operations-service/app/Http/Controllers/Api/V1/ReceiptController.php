<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReceiptController extends Controller
{
    public function show(Request $request, $id)
    {
        // 1. Fetch Sale
        $sale = DB::table('sales')->where('id', $id)->first();
        if (!$sale) {
            // Try matching by receipt_number if ID not found
            $sale = DB::table('sales')->where('receipt_number', $id)->first();
        }

        if (!$sale) {
            return response()->json([
                'status' => 'error',
                'message' => 'Sale receipt not found.',
            ], 404);
        }

        // Increment print_count if explicitly requested as reprint or if fetched after initial checkout
        $isReprintRequest = $request->query('reprint') === 'true' || $request->query('reprint') === '1';
        if ($isReprintRequest) {
            DB::table('sales')->where('id', $sale->id)->increment('print_count');
            $sale->print_count += 1;
        }

        // 2. Fetch Sale Lines
        $lines = DB::table('sale_lines')->where('sale_id', $sale->id)->get();

        // 3. Fetch Outlet Details
        $outlet = DB::table('outlets')->where('id', $sale->outlet_id)->first();
        if (!$outlet) {
            $outlet = (object) [
                'name' => 'Main Outlet',
                'code' => 'MAIN',
                'address' => 'Phnom Penh, Cambodia',
                'phone' => '+855 12 345 678',
                'receipt_header' => 'Thank you for shopping with us!',
                'receipt_footer' => 'Please keep this receipt for returns within 7 days.',
            ];
        }

        // 4. Fetch Cashier User Details
        $cashier = DB::table('users')->where('id', $sale->user_id)->select('id', 'name', 'email')->first();
        if (!$cashier) {
            $cashier = (object) ['name' => 'Cashier'];
        }

        // 5. Fetch Register Details
        $register = DB::table('registers')->where('id', $sale->register_id)->first();

        // 6. Fetch Payments
        $payments = DB::table('payments')->where('sale_id', $sale->id)->get();

        $printCount = (int) ($sale->print_count ?? 1);
        $isReprint = $printCount > 1;
        $printBadge = $isReprint ? "*** REPRINT RECEIPT (#{$printCount}) ***" : "*** ORIGINAL RECEIPT ***";

        return response()->json([
            'status' => 'success',
            'data' => [
                'sale' => $sale,
                'lines' => $lines,
                'outlet' => $outlet,
                'cashier' => $cashier,
                'register' => $register,
                'payments' => $payments,
                'is_reprint' => $isReprint,
                'print_count' => $printCount,
                'print_badge' => $printBadge,
            ],
        ]);
    }
}
