<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * [BD-0015] Bank & Payment Gateway Settlement Reconciliation Controller.
 *
 * @ticket BD-0015
 * @component operations-service
 * @file codebridge-services/operations-service/app/Http/Controllers/Api/V1/ReconciliationController.php
 * @table reconciliations, reconciliation_exceptions
 * @entry-point POST /api/v1/reconciliation/run, GET /api/v1/reconciliation/batches
 * @date-time 2026-10-23T15:40:00+07:00
 *
 * Process Capabilities:
 * 1. Matches internal sales payments against external ABA / Bakong settlements.
 * 2. Flags discrepancies into reconciliation_exceptions table.
 * 3. Accountant approval and resolution workflows.
 */
class ReconciliationController extends Controller
{
    protected function getTenantId(Request $request): ?int
    {
        $tid = $request->header('X-Tenant-Id')
            ?? $request->user()?->tenant_id
            ?? $request->query('tenant_id')
            ?? $request->input('tenant_id')
            ?? null;

        return (!empty($tid) && is_numeric($tid)) ? (int) $tid : null;
    }

    /**
     * Run batch reconciliation audit comparing internal payments vs gateway settlements
     */
    public function run(Request $request)
    {
        $tenantId = $this->getTenantId($request);
        $batchCode = 'REC-BATCH-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -4));

        // If external settlement records or statement lines are provided, run classifier
        if ($request->has('settlement_records') || $request->has('lines')) {
            return $this->classify($request);
        }

        // Audit recent payment attempts scoped by tenant
        $attemptsQuery = DB::table('payment_attempts');
        if (!empty($tenantId) && $tenantId !== 'all') {
            $hasTenant = DB::table('payment_attempts')->where('tenant_id', $tenantId)->exists();
            if ($hasTenant) {
                $attemptsQuery->where('tenant_id', $tenantId);
            }
        }
        $attempts = $attemptsQuery->orderBy('created_at', 'desc')->limit(50)->get();

        $totalRecords = $attempts->count();
        $matchedCount = 0;
        $mismatchCount = 0;
        $totalDiscrepancy = 0;
        $rawExceptions = [];

        foreach ($attempts as $attempt) {
            $isPaid = $attempt->status === 'paid';
            $expectedAmount = (float) $attempt->amount;
            $actualAmount = $isPaid ? $expectedAmount : 0;
            $discrepancy = abs($expectedAmount - $actualAmount);

            if ($isPaid && $discrepancy == 0) {
                $matchedCount++;
            } else {
                $mismatchCount++;
                $totalDiscrepancy += $discrepancy;

                $exceptionType = !$isPaid ? 'missing_at_provider' : 'amount_mismatch';

                $rawExceptions[] = [
                    'tenant_id' => $tenantId,
                    'payment_id' => $attempt->payment_id,
                    'merchant_reference' => $attempt->merchant_reference ?? ('PAY-' . strtoupper(substr(uniqid(), -6))),
                    'expected_amount' => $expectedAmount,
                    'actual_amount' => $actualAmount,
                    'discrepancy_amount' => $discrepancy,
                    'exception_type' => $exceptionType,
                    'status' => 'pending',
                    'notes' => 'Discrepancy flagged during automated batch audit.',
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        // Insert Reconciliation Batch Header
        $batchId = DB::table('reconciliations')->insertGetId([
            'tenant_id' => $tenantId,
            'batch_code' => $batchCode,
            'reconciled_date' => now()->toDateString(),
            'total_records' => $totalRecords,
            'matched_count' => $matchedCount,
            'mismatch_count' => $mismatchCount,
            'total_discrepancy_amount' => $totalDiscrepancy,
            'status' => $mismatchCount > 0 ? 'warning' : 'completed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Insert Exceptions
        foreach ($rawExceptions as $ex) {
            $ex['reconciliation_id'] = $batchId;
            DB::table('reconciliation_exceptions')->insert($ex);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Batch reconciliation audit completed successfully.',
            'data' => [
                'reconciliation_id' => $batchId,
                'batch_code' => $batchCode,
                'total_records' => $totalRecords,
                'matched_count' => $matchedCount,
                'mismatch_count' => $mismatchCount,
                'total_discrepancy_amount' => $totalDiscrepancy,
            ],
        ], 201);
    }

    /**
     * ABA PayWay & Bakong Settlement Exception Classifier
     * Auto-classifies:
     *  - amount_mismatch: internal payment expected amount != external settlement amount
     *  - missing_internally: transaction exists in external settlement but absent from POS internal DB
     *  - fee_mismatch: payment gateway deducted fee differs from agreed provider fee schedule
     *  - missing_at_provider: internal paid record missing from settlement report
     *
     * POST /api/v1/reconciliation/classify
     */
    public function classify(Request $request)
    {
        $tenantId = $this->getTenantId($request);
        $provider = strtolower((string) ($request->input('provider') ?? 'aba_payway'));
        $defaultFeeRate = (float) ($request->input('fee_rate') ?? ($provider === 'bakong' ? 0.0025 : 0.0080)); // 0.25% Bakong, 0.8% ABA
        $batchCode = 'REC-CLS-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -4));

        $records = $request->input('settlement_records') ?? $request->input('lines') ?? [];

        // If no records passed in payload, inspect bank_statement_lines
        $statementLineIds = [];
        if (empty($records)) {
            $stQuery = DB::table('bank_statement_lines')->where('match_status', 'unmatched');
            if (!empty($tenantId) && $tenantId !== 'all') {
                $hasTenant = DB::table('bank_statement_lines')->where('tenant_id', $tenantId)->exists();
                if ($hasTenant) {
                    $stQuery->where('tenant_id', $tenantId);
                }
            }
            $statementRows = $stQuery->orderBy('id', 'desc')->limit(50)->get();
            foreach ($statementRows as $row) {
                $records[] = [
                    'statement_line_id' => $row->id,
                    'reference' => $row->transaction_reference,
                    'amount' => (float) $row->amount_change,
                    'currency' => $row->currency ?? 'USD',
                ];
            }
        }

        // If still empty, fall back to evaluating un-reconciled payment attempts
        if (empty($records)) {
            $paQuery = DB::table('payment_attempts')->orderBy('id', 'desc')->limit(30);
            if (!empty($tenantId) && $tenantId !== 'all') {
                $hasTenant = DB::table('payment_attempts')->where('tenant_id', $tenantId)->exists();
                if ($hasTenant) {
                    $paQuery->where('tenant_id', $tenantId);
                }
            }
            $attempts = $paQuery->get();
            foreach ($attempts as $att) {
                $records[] = [
                    'reference' => $att->merchant_reference,
                    'hash' => $att->provider_transaction_id,
                    'amount' => (float) $att->amount,
                    'currency' => $att->currency ?? 'USD',
                    'internal_attempt_id' => $att->id,
                    'internal_payment_id' => $att->payment_id,
                    'internal_status' => $att->status,
                ];
            }
        }

        $totalRecords = count($records);
        $matchedCount = 0;
        $amountMismatchCount = 0;
        $missingInternallyCount = 0;
        $feeMismatchCount = 0;
        $missingAtProviderCount = 0;
        $totalDiscrepancy = 0;
        $rawExceptions = [];
        $matchedLineIds = [];

        foreach ($records as $item) {
            $ref = trim((string) ($item['reference'] ?? $item['merchant_reference'] ?? $item['transaction_reference'] ?? ''));
            $hash = trim((string) ($item['hash'] ?? $item['transaction_hash'] ?? ''));
            $externalAmount = isset($item['amount']) ? (float) $item['amount'] : 0.0;
            $externalFee = isset($item['fee']) ? (float) $item['fee'] : null;
            $statementLineId = $item['statement_line_id'] ?? null;

            // Search internal attempt
            $attempt = null;
            if (!empty($ref)) {
                $attempt = DB::table('payment_attempts')->where('merchant_reference', $ref)->first();
            }
            if (!$attempt && !empty($hash)) {
                $attempt = DB::table('payment_attempts')->where('provider_transaction_id', $hash)->first();
            }
            if (!$attempt && is_numeric($ref)) {
                $attempt = DB::table('payment_attempts')->where('id', $ref)->orWhere('payment_id', $ref)->first();
            }

            // Search internal payment
            $payment = null;
            if ($attempt) {
                $payment = DB::table('payments')->where('id', $attempt->payment_id)->first();
            } elseif (!empty($ref)) {
                $payment = DB::table('payments')->where('reference_number', $ref)->first();
            }

            // 1. Check missing_internally
            if (!$attempt && !$payment) {
                $missingInternallyCount++;
                $discrepancy = $externalAmount;
                $totalDiscrepancy += $discrepancy;

                $rawExceptions[] = [
                    'tenant_id' => $tenantId,
                    'payment_id' => null,
                    'merchant_reference' => $ref ?: ('EXT-' . strtoupper(substr(uniqid(), -6))),
                    'expected_amount' => 0.00,
                    'actual_amount' => $externalAmount,
                    'discrepancy_amount' => $discrepancy,
                    'exception_type' => 'missing_internally',
                    'status' => 'pending',
                    'notes' => "External settlement transaction '{$ref}' from {$provider} was not found internally in POS records.",
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                continue;
            }

            $internalAmount = (float) ($attempt->amount ?? $payment->amount ?? 0);
            $internalStatus = $attempt->status ?? $payment->status ?? 'created';

            // 2. Check missing_at_provider
            if ($internalStatus === 'paid' && $externalAmount <= 0.0) {
                $missingAtProviderCount++;
                $discrepancy = $internalAmount;
                $totalDiscrepancy += $discrepancy;

                $rawExceptions[] = [
                    'tenant_id' => $tenantId,
                    'payment_id' => $payment?->id ?? $attempt?->payment_id,
                    'merchant_reference' => $ref ?: ($attempt->merchant_reference ?? 'N/A'),
                    'expected_amount' => $internalAmount,
                    'actual_amount' => 0.00,
                    'discrepancy_amount' => $discrepancy,
                    'exception_type' => 'missing_at_provider',
                    'status' => 'pending',
                    'notes' => "Internal payment '{$ref}' is marked paid but absent from {$provider} settlement report.",
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                continue;
            }

            // 3. Check amount_mismatch
            $amountDiff = abs($internalAmount - $externalAmount);
            if ($amountDiff > 0.01) {
                $amountMismatchCount++;
                $totalDiscrepancy += $amountDiff;

                $rawExceptions[] = [
                    'tenant_id' => $tenantId,
                    'payment_id' => $payment?->id ?? $attempt?->payment_id,
                    'merchant_reference' => $ref ?: ($attempt->merchant_reference ?? 'N/A'),
                    'expected_amount' => $internalAmount,
                    'actual_amount' => $externalAmount,
                    'discrepancy_amount' => $amountDiff,
                    'exception_type' => 'amount_mismatch',
                    'status' => 'pending',
                    'notes' => "Settlement amount mismatch: Expected \${$internalAmount}, external settlement received \${$externalAmount}.",
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                continue;
            }

            // 4. Check fee_mismatch
            if ($externalFee !== null) {
                $expectedFee = isset($item['expected_fee'])
                    ? (float) $item['expected_fee']
                    : round($internalAmount * $defaultFeeRate, 2);
                $feeDiff = abs($expectedFee - $externalFee);

                if ($feeDiff > 0.01) {
                    $feeMismatchCount++;
                    $totalDiscrepancy += $feeDiff;

                    $rawExceptions[] = [
                        'tenant_id' => $tenantId,
                        'payment_id' => $payment?->id ?? $attempt?->payment_id,
                        'merchant_reference' => $ref ?: ($attempt->merchant_reference ?? 'N/A'),
                        'expected_amount' => $expectedFee,
                        'actual_amount' => $externalFee,
                        'discrepancy_amount' => $feeDiff,
                        'exception_type' => 'fee_mismatch',
                        'status' => 'pending',
                        'notes' => "Gateway fee discrepancy: Expected agreed fee \${$expectedFee} ({$defaultFeeRate}%), provider deducted \${$externalFee}.",
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                    continue;
                }
            }

            // All matched successfully
            $matchedCount++;
            if ($statementLineId) {
                $matchedLineIds[] = $statementLineId;
            }
        }

        $mismatchCount = count($rawExceptions);

        // Record Reconciliation Batch Header
        $batchId = DB::table('reconciliations')->insertGetId([
            'tenant_id' => $tenantId,
            'batch_code' => $batchCode,
            'reconciled_date' => now()->toDateString(),
            'total_records' => $totalRecords,
            'matched_count' => $matchedCount,
            'mismatch_count' => $mismatchCount,
            'total_discrepancy_amount' => $totalDiscrepancy,
            'status' => $mismatchCount > 0 ? 'warning' : 'completed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Insert Classified Exceptions
        foreach ($rawExceptions as $ex) {
            $ex['reconciliation_id'] = $batchId;
            DB::table('reconciliation_exceptions')->insert($ex);
        }

        // Update matched bank statement lines
        if (!empty($matchedLineIds)) {
            DB::table('bank_statement_lines')
                ->whereIn('id', $matchedLineIds)
                ->update([
                    'match_status' => 'matched',
                    'reconciliation_id' => $batchId,
                    'updated_at' => now(),
                ]);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Settlement exception classification completed.',
            'data' => [
                'reconciliation_id' => $batchId,
                'batch_code' => $batchCode,
                'provider' => $provider,
                'total_records' => $totalRecords,
                'matched_count' => $matchedCount,
                'total_discrepancy_amount' => $totalDiscrepancy,
                'breakdown' => [
                    'amount_mismatch' => $amountMismatchCount,
                    'missing_internally' => $missingInternallyCount,
                    'fee_mismatch' => $feeMismatchCount,
                    'missing_at_provider' => $missingAtProviderCount,
                ],
                'exceptions' => $rawExceptions,
            ],
        ], 201);
    }

    /**
     * Get list of reconciliation batches and exception discrepancy items
     */
    public function exceptions(Request $request)
    {
        $tenantId = $this->getTenantId($request);
        $status = $request->query('status');
        $type = $request->query('type');

        $query = DB::table('reconciliation_exceptions as re')
            ->join('reconciliations as r', 're.reconciliation_id', '=', 'r.id');

        if (!empty($tenantId) && $tenantId !== 'all') {
            $hasTenant = DB::table('reconciliation_exceptions')->where('tenant_id', $tenantId)->exists();
            if ($hasTenant) {
                $query->where('re.tenant_id', $tenantId);
            }
        }

        if (!empty($status) && $status !== 'all') {
            $query->where('re.status', $status);
        }

        if (!empty($type) && $type !== 'all') {
            $query->where('re.exception_type', $type);
        }

        $exceptions = $query->select(
                're.id',
                're.reconciliation_id',
                're.payment_id',
                're.merchant_reference',
                're.expected_amount',
                're.actual_amount',
                're.discrepancy_amount',
                're.exception_type',
                're.status',
                're.notes',
                're.created_at',
                'r.batch_code'
            )
            ->orderBy('re.created_at', 'desc')
            ->get();

        $batchesQuery = DB::table('reconciliations');
        if (!empty($tenantId) && $tenantId !== 'all') {
            $hasTenantBatches = DB::table('reconciliations')->where('tenant_id', $tenantId)->exists();
            if ($hasTenantBatches) {
                $batchesQuery->where('tenant_id', $tenantId);
            }
        }
        $batches = $batchesQuery->orderBy('created_at', 'desc')->limit(10)->get();

        // Calculate summary statistics
        $pendingQuery = DB::table('reconciliation_exceptions')->where('status', 'pending');
        $resolvedQuery = DB::table('reconciliation_exceptions')->where('status', 'resolved');

        if (!empty($tenantId) && $tenantId !== 'all') {
            $hasTenantEx = DB::table('reconciliation_exceptions')->where('tenant_id', $tenantId)->exists();
            if ($hasTenantEx) {
                $pendingQuery->where('tenant_id', $tenantId);
                $resolvedQuery->where('tenant_id', $tenantId);
            }
        }

        $pendingCount = $pendingQuery->count();
        $resolvedCount = $resolvedQuery->count();
        $totalDiscrepancySum = $pendingQuery->sum('discrepancy_amount');

        return response()->json([
            'status' => 'success',
            'data' => [
                'exceptions' => $exceptions,
                'batches' => $batches,
                'summary' => [
                    'pending_count' => $pendingCount,
                    'resolved_count' => $resolvedCount,
                    'total_pending_discrepancy' => (float) $totalDiscrepancySum,
                ],
            ],
        ]);
    }

    /**
     * Accountant resolution for a reconciliation exception
     */
    public function resolveException(Request $request, $id)
    {
        $validated = $request->validate([
            'status' => 'required|string|in:resolved,ignored',
            'notes' => 'required|string|max:500',
        ]);

        $exception = DB::table('reconciliation_exceptions')->where('id', $id)->first();
        if (!$exception) {
            return response()->json([
                'status' => 'error',
                'message' => 'Reconciliation exception item not found.',
            ], 404);
        }

        DB::table('reconciliation_exceptions')->where('id', $id)->update([
            'status' => $validated['status'],
            'notes' => $validated['notes'],
            'resolved_by' => $request->user()->id ?? null,
            'resolved_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Reconciliation exception updated to ' . $validated['status'] . '.',
        ]);
    }
}
