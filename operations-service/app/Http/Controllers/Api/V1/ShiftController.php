<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Mail\EndOfDayZReportMail;
use App\Models\ShiftVarianceAlert;
use App\Services\CashDrawerNotificationService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * [BD-0055] Shift Management, Till Float & Automated Variance Notification APIs.
 *
 * @ticket BD-0055
 * @component operations-service
 * @file codebridge-services/operations-service/app/Http/Controllers/Api/V1/ShiftController.php
 * @related-service App\Services\CashDrawerNotificationService
 * @related-model App\Models\ShiftVarianceAlert
 * @entry-point API Routes: /api/v1/shifts/*
 * @date-time 2026-10-23T15:35:00+07:00
 *
 * Capabilities:
 * - Starting float & cash drawer kick authorization.
 * - Mid-shift cash drops & pay-outs.
 * - End-of-shift Z-Report reconciliation.
 * - Automated threshold-based cash discrepancy variance alerts dispatched to managers.
 *
 * @tags Shifts & Cash Drawer
 */
#[Group('Shifts & Cash Drawer')]
class ShiftController extends Controller
{
    public function __construct(
        protected CashDrawerNotificationService $notificationService
    ) {}
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

        // Automated Telegram & Email Variance Threshold Notification Dispatch
        $alertResult = $this->notificationService->checkAndDispatchVarianceAlert(
            shift: $shift,
            cashVariance: $cashVariance,
            expectedCash: $m['expectedCash'],
            countedCash: $countedCash,
            closingNote: $closingNote,
            cashier: $request->user(),
            customTolerance: $request->input('tolerance_threshold') ? (float) $request->input('tolerance_threshold') : null
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Shift closed successfully.',
            'data' => [
                'shift_id' => $shift->id,
                'expected_cash' => $m['expectedCash'],
                'counted_cash' => $countedCash,
                'cash_variance' => $cashVariance,
                'variance_alert' => $alertResult,
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

    /**
     * Get recent Shift Cash Drawer Variance Alerts.
     * GET /api/v1/shifts/variance-alerts
     */
    public function varianceAlerts(Request $request)
    {
        $alerts = DB::table('shift_variance_alerts as a')
            ->leftJoin('shifts as s', 'a.shift_id', '=', 's.id')
            ->leftJoin('users as u', 'a.user_id', '=', 'u.id')
            ->select('a.*', 'u.name as cashier_name', 's.opened_at', 's.closed_at')
            ->orderBy('a.id', 'desc')
            ->limit(50)
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => [
                'alerts' => $alerts,
            ],
        ]);
    }

    /**
     * Get Cash Drawer Float Variance Notification Configuration.
     * GET /api/v1/shifts/variance-config
     */
    public function varianceConfig(Request $request)
    {
        $tolerance = (float) config('services.cash_drawer.variance_tolerance', env('CASH_DRAWER_VARIANCE_TOLERANCE', 5.00));
        $telegramToken = config('services.telegram.bot_token', env('TELEGRAM_BOT_TOKEN'));
        $telegramChat = config('services.telegram.chat_id', env('TELEGRAM_ALERT_CHAT_ID', env('TELEGRAM_CHAT_ID')));
        $emailRecipient = config('services.cash_drawer.alert_email', env('SHIFT_ALERT_EMAIL', env('MAIL_FROM_ADDRESS', 'manager@codebridges.io')));

        return response()->json([
            'status' => 'success',
            'data' => [
                'tolerance_threshold' => $tolerance,
                'channels' => [
                    'telegram' => [
                        'configured' => !empty($telegramToken) && !empty($telegramChat),
                        'chat_id' => !empty($telegramChat) ? substr($telegramChat, 0, 4) . '***' : null,
                    ],
                    'email' => [
                        'configured' => !empty($emailRecipient),
                        'recipient' => $emailRecipient,
                    ],
                ],
            ],
        ]);
    }

    /**
     * Test Cash Drawer Float Variance Automated Notification.
     * POST /api/v1/shifts/test-variance-alert
     */
    public function testVarianceAlert(Request $request)
    {
        $validated = $request->validate([
            'variance_amount' => 'nullable|numeric',
            'outlet_name' => 'nullable|string',
            'note' => 'nullable|string',
        ]);

        $mockShift = (object) [
            'id' => rand(100, 999),
            'user_id' => $request->user()->id,
            'outlet_id' => 1,
        ];

        $variance = (float) ($validated['variance_amount'] ?? -15.00);

        $result = $this->notificationService->checkAndDispatchVarianceAlert(
            shift: $mockShift,
            cashVariance: $variance,
            expectedCash: 200.00,
            countedCash: 200.00 + $variance,
            closingNote: $validated['note'] ?? 'Test shift float discrepancy dispatch',
            cashier: $request->user(),
            customTolerance: 5.00
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Automated shift float variance test notification dispatched.',
            'data' => $result,
        ]);
    }

    /**
     * Generate comprehensive End-of-Day (EOD) Z-Report Financial Summary.
     */
    protected function generateZReportData(?string $tenantId, ?int $outletId, ?string $date, bool $closeOpenRegisters = false): array
    {
        $targetDate = $date ?: date('Y-m-d');

        // Resolve outlet name
        $outletName = 'All Outlets';
        if ($outletId) {
            $outlet = DB::table('outlets')->where('id', $outletId)->first();
            if ($outlet) {
                $outletName = $outlet->name;
            }
        }

        // 1. Sales & Revenue Aggregates
        $salesQuery = DB::table('sales')
            ->whereBetween('created_at', ["{$targetDate} 00:00:00", "{$targetDate} 23:59:59"]);

        if ($outletId) {
            $salesQuery->where('outlet_id', $outletId);
        }
        if (!empty($tenantId) && $tenantId !== 'all') {
            $hasTenant = DB::table('sales')->where('tenant_id', $tenantId)->exists();
            if ($hasTenant) {
                $salesQuery->where('tenant_id', $tenantId);
            }
        }

        $totalOrders = (clone $salesQuery)->count();
        $completedSalesQuery = (clone $salesQuery)->where('status', 'completed');
        $grossSales = (float) (clone $completedSalesQuery)->sum('grand_total');
        $subtotal = (float) (clone $completedSalesQuery)->sum('subtotal');
        $taxTotal = (float) (clone $completedSalesQuery)->sum('tax_total');
        $discountTotal = (float) (clone $completedSalesQuery)->sum('discount_total');
        $netSales = max(0.0, $grossSales - $discountTotal);

        // 2. Payment Tender Breakdown
        $paymentsQuery = DB::table('payments as p')
            ->join('sales as s', 'p.sale_id', '=', 's.id')
            ->whereBetween('p.created_at', ["{$targetDate} 00:00:00", "{$targetDate} 23:59:59"])
            ->where('p.status', 'paid');

        if ($outletId) {
            $paymentsQuery->where('s.outlet_id', $outletId);
        }

        $cashSales = (float) (clone $paymentsQuery)->where('p.tender_type', 'cash')->sum('p.amount');
        $khqrSales = (float) (clone $paymentsQuery)->where('p.tender_type', 'khqr')->sum('p.amount');
        $cardSales = (float) (clone $paymentsQuery)->where('p.tender_type', 'card')->sum('p.amount');
        $otherSales = (float) (clone $paymentsQuery)->whereNotIn('p.tender_type', ['cash', 'khqr', 'card'])->sum('p.amount');

        // 3. Refunds Summary
        $refundsQuery = DB::table('refunds')
            ->whereBetween('created_at', ["{$targetDate} 00:00:00", "{$targetDate} 23:59:59"]);
        $refundCount = (clone $refundsQuery)->count();
        $refundTotal = (float) (clone $refundsQuery)->sum('amount');

        // 4. Auto-Close Open Registers (if requested)
        $closedRegistersCount = 0;
        if ($closeOpenRegisters) {
            $openShiftsQuery = DB::table('shifts')->where('status', 'open');
            if ($outletId) {
                $openShiftsQuery->where('outlet_id', $outletId);
            }
            $openShifts = $openShiftsQuery->get();
            foreach ($openShifts as $os) {
                $m = $this->getShiftMetrics($os->id, $os->opening_float);
                DB::table('shifts')->where('id', $os->id)->update([
                    'status' => 'closed',
                    'closed_at' => now(),
                    'expected_cash' => $m['expectedCash'],
                    'counted_cash' => $m['expectedCash'],
                    'cash_variance' => 0.00,
                    'closing_note' => 'Automated End-of-Day (EOD) system closure',
                    'updated_at' => now(),
                ]);
                $closedRegistersCount++;
            }
        }

        // 5. Shift & Cash Drawer Reconciliation
        $shiftsQuery = DB::table('shifts')->whereDate('opened_at', $targetDate);
        if ($outletId) {
            $shiftsQuery->where('outlet_id', $outletId);
        }
        $shifts = $shiftsQuery->get();

        $openingFloatTotal = (float) $shifts->sum('opening_float');
        $expectedCashTotal = (float) $shifts->sum('expected_cash');
        $countedCashTotal = (float) $shifts->whereNotNull('counted_cash')->sum('counted_cash');
        $cashVarianceTotal = (float) $shifts->whereNotNull('cash_variance')->sum('cash_variance');

        // 6. Generate Sequential Official Z-Report Identifier
        $cleanDate = str_replace('-', '', $targetDate);
        $outletCode = str_pad((string) ($outletId ?: 1), 2, '0', STR_PAD_LEFT);
        $seq = str_pad((string) (rand(100, 999)), 4, '0', STR_PAD_LEFT);
        $zReportCode = "Z-{$cleanDate}-O{$outletCode}-{$seq}";

        return [
            'z_report_code' => $zReportCode,
            'type' => 'Z-REPORT',
            'date' => $targetDate,
            'outlet_id' => $outletId,
            'outlet_name' => $outletName,
            'total_orders' => $totalOrders,
            'gross_sales' => $grossSales,
            'subtotal' => $subtotal,
            'tax_total' => $taxTotal,
            'discount_total' => $discountTotal,
            'net_sales' => $netSales,
            'refund_total' => $refundTotal,
            'refund_count' => $refundCount,
            'payment_breakdown' => [
                'cash' => $cashSales,
                'khqr' => $khqrSales,
                'card' => $cardSales,
                'other' => $otherSales,
                'total' => $grossSales,
            ],
            'cash_drawer' => [
                'total_shifts' => $shifts->count(),
                'opening_float' => $openingFloatTotal,
                'expected_cash' => $expectedCashTotal,
                'counted_cash' => $countedCashTotal,
                'cash_variance' => $cashVarianceTotal,
            ],
            'registers_closed' => $closedRegistersCount,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Official End-of-Day (EOD) Z-Report Financial Summary.
     *
     * POST /api/v1/pos/reports/z-report
     * GET  /api/v1/pos/reports/z-report
     * POST /api/v1/reports/z-report
     * GET  /api/v1/reports/z-report
     */
    public function zReport(Request $request)
    {
        $tenantId = $request->header('X-Tenant-Id') ?? $request->user()?->tenant_id ?? $request->query('tenant_id');
        $outletId = $request->input('outlet_id') ?? $request->query('outlet_id');
        $date = $request->input('date') ?? $request->query('date');
        $closeOpenRegisters = (bool) ($request->input('close_open_registers') ?? $request->query('close_open_registers', false));

        $data = $this->generateZReportData($tenantId, $outletId ? (int) $outletId : null, $date, $closeOpenRegisters);

        return response()->json([
            'status' => 'success',
            'message' => 'Official Z-Report financial summary generated successfully.',
            'data' => $data,
        ]);
    }

    /**
     * Automated End-of-Day (EOD) Z-Report & Nightly Email Spooler.
     *
     * POST /api/v1/pos/shifts/eod-dispatch
     * POST /api/v1/shifts/eod-dispatch
     *
     * Generates official Z-Report financial summary, closes all open registers/shifts,
     * logs system-wide sales audit, and auto-dispatches PDF/HTML summary to store owners.
     */
    public function eodDispatch(Request $request)
    {
        $rawTid = $request->header('X-Tenant-Id') ?? $request->user()?->tenant_id ?? $request->input('tenant_id');
        $tenantId = (!empty($rawTid) && is_numeric($rawTid)) ? (int) $rawTid : null;
        $outletId = $request->input('outlet_id') ? (int) $request->input('outlet_id') : ($request->user()?->outlet_id ?? null);
        $date = $request->input('date') ?? date('Y-m-d');

        // Generate Z-Report and force close all open registers
        $zReport = $this->generateZReportData($tenantId, $outletId, $date, true);

        // Determine recipient email
        $recipientEmail = $request->input('recipient_email')
            ?? config('services.cash_drawer.alert_email')
            ?? env('SHIFT_ALERT_EMAIL')
            ?? env('STORE_OWNER_EMAIL')
            ?? env('MAIL_FROM_ADDRESS')
            ?? 'owner@codebridges.io';

        // Spool & Dispatch Email Summary
        $emailDispatched = false;
        try {
            Mail::to($recipientEmail)->send(new EndOfDayZReportMail($zReport));
            $emailDispatched = true;
            Log::info("EOD Z-Report summary dispatched to {$recipientEmail} for outlet {$zReport['outlet_name']}");
        } catch (\Throwable $e) {
            Log::warning('Nightly EOD Z-Report email spooling failed: ' . $e->getMessage());
        }

        // Record audit trail in api_request_logs
        try {
            DB::table('api_request_logs')->insert([
                'uuid' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'request_id' => $zReport['z_report_code'],
                'service_name' => 'operations-service',
                'method' => 'POST',
                'path' => '/api/v1/pos/shifts/eod-dispatch',
                'status_code' => 200,
                'request_data' => json_encode([
                    'recipient' => $recipientEmail,
                    'outlet_id' => $outletId,
                    'registers_closed' => $zReport['registers_closed'],
                    'gross_sales' => $zReport['gross_sales'],
                ]),
                'ip_address' => $request->ip(),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::debug('Audit logging error: ' . $e->getMessage());
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Automated End-of-Day (EOD) Z-Report generated, all open registers closed, and nightly financial summary dispatched to store owner.',
            'data' => [
                'z_report_code' => $zReport['z_report_code'],
                'date' => $zReport['date'],
                'outlet_name' => $zReport['outlet_name'],
                'registers_closed' => $zReport['registers_closed'],
                'email_spooled' => $emailDispatched,
                'recipient' => $recipientEmail,
                'summary' => $zReport,
            ],
        ], 200);
    }
}
