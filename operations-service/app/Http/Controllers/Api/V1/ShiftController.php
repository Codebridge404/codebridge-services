<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ShiftController extends Controller
{
    private function getShiftMetrics($shiftId, $openingFloat)
    {
        $cashSales = (float) DB::table('sales as s')
            ->join('payments as p', 's.id', '=', 'p.sale_id')
            ->where('s.shift_id', $shiftId)->where('p.tender_type', 'cash')->where('p.status', 'paid')
            ->sum('p.amount');

        $khqrSales = (float) DB::table('sales as s')
            ->join('payments as p', 's.id', '=', 'p.sale_id')
            ->where('s.shift_id', $shiftId)->where('p.tender_type', 'khqr')->where('p.status', 'paid')
            ->sum('p.amount');

        $cardSales = (float) DB::table('sales as s')
            ->join('payments as p', 's.id', '=', 'p.sale_id')
            ->where('s.shift_id', $shiftId)->where('p.tender_type', 'card')->where('p.status', 'paid')
            ->sum('p.amount');

        $cashIn = (float) DB::table('cash_drawer_movements')->where('shift_id', $shiftId)->where('type', 'in')->sum('amount');
        $cashOut = (float) DB::table('cash_drawer_movements')->where('shift_id', $shiftId)->where('type', 'out')->sum('amount');
        $expectedCash = (float) $openingFloat + $cashSales + $cashIn - $cashOut;

        return compact('cashSales', 'khqrSales', 'cardSales', 'cashIn', 'cashOut', 'expectedCash');
    }

    public function active(Request $request)
    {
        $shift = DB::table('shifts as s')
            ->leftJoin('users as u', 's.user_id', '=', 'u.id')
            ->leftJoin('outlets as o', 's.outlet_id', '=', 'o.id')
            ->where('s.user_id', $request->user()->id)
            ->where('s.status', 'open')
            ->select('s.*', 'u.name as cashier_name', 'o.name as outlet_name')
            ->orderBy('s.id', 'desc')->first();

        if (!$shift) {
            return response()->json(['status' => 'success', 'data' => null]);
        }

        $m = $this->getShiftMetrics($shift->id, $shift->opening_float);

        return response()->json([
            'status' => 'success',
            'data' => [
                'shift' => $shift,
                'summary' => [
                    'opening_float' => (float)$shift->opening_float,
                    'cash_sales' => $m['cashSales'],
                    'khqr_sales' => $m['khqrSales'],
                    'card_sales' => $m['cardSales'],
                    'cash_in' => $m['cashIn'],
                    'cash_out' => $m['cashOut'],
                    'expected_cash' => $m['expectedCash'],
                ],
            ],
        ]);
    }

    public function open(Request $request)
    {
        $user = $request->user();
        $existing = DB::table('shifts')->where('user_id', $user->id)->where('status', 'open')->first();
        if ($existing) {
            return response()->json(['status' => 'error', 'message' => 'Active open shift already exists.', 'data' => $existing], 422);
        }

        $validated = $request->validate([
            'opening_float' => 'required|numeric|min:0',
            'outlet_id' => 'nullable',
            'register_id' => 'nullable',
            'note' => 'nullable|string',
        ]);

        $shiftId = DB::table('shifts')->insertGetId([
            'outlet_id' => $validated['outlet_id'] ?? $user->outlet_id ?? 1,
            'register_id' => $validated['register_id'] ?? 1,
            'user_id' => $user->id,
            'opened_at' => now(),
            'opening_float' => $validated['opening_float'],
            'expected_cash' => $validated['opening_float'],
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $shift = DB::table('shifts as s')
            ->leftJoin('users as u', 's.user_id', '=', 'u.id')
            ->leftJoin('outlets as o', 's.outlet_id', '=', 'o.id')
            ->select('s.*', 'u.name as cashier_name', 'o.name as outlet_name')
            ->where('s.id', $shiftId)->first();

        return response()->json(['status' => 'success', 'message' => 'Shift opened successfully.', 'data' => $shift], 201);
    }

    public function cashMovement(Request $request, $id = null)
    {
        $validated = $request->validate([
            'type' => 'required|in:in,out',
            'amount' => 'required|numeric|min:0.01',
            'reason' => 'required|string|max:255',
        ]);

        $query = DB::table('shifts')->where('status', 'open');
        $shift = ($id ? $query->where('id', $id) : $query->where('user_id', $request->user()->id)->orderByDesc('id'))->first();
        if (!$shift) {
            return response()->json(['status' => 'error', 'message' => 'Active open shift not found.'], 404);
        }

        $movementId = DB::table('cash_drawer_movements')->insertGetId([
            'shift_id' => $shift->id,
            'user_id' => $request->user()->id,
            'type' => $validated['type'],
            'amount' => $validated['amount'],
            'reason' => $validated['reason'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['status' => 'success', 'message' => 'Cash drawer movement recorded.', 'data' => ['id' => $movementId]]);
    }

    public function close(Request $request, $id = null)
    {
        $validated = $request->validate([
            'counted_cash' => 'required|numeric|min:0',
            'closing_note' => 'nullable|string',
            'note' => 'nullable|string',
        ]);

        $query = DB::table('shifts')->where('status', 'open');
        $shift = ($id ? $query->where('id', $id) : $query->where('user_id', $request->user()->id)->orderByDesc('id'))->first();
        if (!$shift) {
            return response()->json(['status' => 'error', 'message' => 'Active open shift not found.'], 404);
        }

        $m = $this->getShiftMetrics($shift->id, $shift->opening_float);
        $countedCash = (float) $validated['counted_cash'];
        $cashVariance = $countedCash - $m['expectedCash'];

        if (abs($cashVariance) > 5.00) {
            $supervisorPin = $request->input('supervisor_pin');
            $userRole = $request->user()->role ?? 'cashier';
            if (!in_array($userRole, ['supervisor', 'outlet_manager', 'admin', 'super_admin'])) {
                if (empty($supervisorPin)) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Cash drawer variance exceeds threshold ($5.00). Supervisor PIN authorization required.',
                        'cash_variance' => $cashVariance,
                    ], 403);
                }
                $validPin = DB::table('users')->where('pin_code', $supervisorPin)->whereIn('role', ['supervisor', 'outlet_manager', 'admin', 'super_admin'])->exists();
                if (!$validPin) {
                    return response()->json(['status' => 'error', 'message' => 'Invalid Supervisor PIN code.'], 403);
                }
            }
        }

        $closingNote = $validated['closing_note'] ?? $validated['note'] ?? null;
        DB::table('shifts')->where('id', $shift->id)->update([
            'closed_at' => now(),
            'expected_cash' => $m['expectedCash'],
            'counted_cash' => $countedCash,
            'cash_variance' => $cashVariance,
            'status' => 'closed',
            'closing_note' => $closingNote,
            'updated_at' => now(),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Shift closed successfully.',
            'data' => [
                'shift_id' => $shift->id,
                'expected_cash' => $m['expectedCash'],
                'counted_cash' => $countedCash,
                'cash_variance' => $cashVariance,
            ],
        ]);
    }

    public function xReport(Request $request, $id = null)
    {
        $query = DB::table('shifts as s')
            ->leftJoin('users as u', 's.user_id', '=', 'u.id')
            ->leftJoin('outlets as o', 's.outlet_id', '=', 'o.id')
            ->select('s.*', 'u.name as cashier_name', 'o.name as outlet_name');

        if ($id && $id !== 'active') {
            $query->where('s.id', $id);
        } else {
            $query->where('s.user_id', $request->user()->id)->where('s.status', 'open')->orderByDesc('s.id');
        }
        $shift = $query->first();

        if (!$shift) {
            return response()->json(['status' => 'error', 'message' => 'Shift not found.'], 404);
        }

        $m = $this->getShiftMetrics($shift->id, $shift->opening_float);
        $grossSales = $m['cashSales'] + $m['khqrSales'] + $m['cardSales'];

        return response()->json([
            'status' => 'success',
            'data' => [
                'type' => $shift->status === 'closed' ? 'Z-REPORT' : 'X-REPORT',
                'report_code' => ($shift->status === 'closed' ? 'Z-' : 'X-') . substr($shift->id, 0, 8),
                'shift' => $shift,
                'cashier_name' => $shift->cashier_name ?? null,
                'outlet_name' => $shift->outlet_name ?? null,
                'opening_float' => (float) $shift->opening_float,
                'cash_sales' => $m['cashSales'],
                'khqr_sales' => $m['khqrSales'],
                'card_sales' => $m['cardSales'],
                'gross_sales' => $grossSales,
                'pay_ins' => $m['cashIn'],
                'pay_outs' => $m['cashOut'],
                'expected_cash' => $m['expectedCash'],
                'counted_cash' => $shift->counted_cash !== null ? (float) $shift->counted_cash : null,
                'cash_variance' => $shift->cash_variance !== null ? (float) $shift->cash_variance : null,
                'timestamp' => now()->toIso8601String(),
            ],
        ]);
    }

    public function history(Request $request)
    {
        $shifts = DB::table('shifts as s')
            ->leftJoin('users as u', 's.user_id', '=', 'u.id')
            ->leftJoin('outlets as o', 's.outlet_id', '=', 'o.id')
            ->select('s.*', 'u.name as cashier_name', 'o.name as outlet_name')
            ->orderBy('s.created_at', 'desc')
            ->limit(50)
            ->get();

        return response()->json(['status' => 'success', 'data' => ['shifts' => $shifts]]);
    }
}
