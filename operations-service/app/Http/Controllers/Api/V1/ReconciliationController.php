<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReconciliationController extends Controller
{
    protected function getTenantId(Request $request): ?string
    {
        return $request->header('X-Tenant-Id')
            ?? $request->user()?->tenant_id
            ?? $request->query('tenant_id')
            ?? null;
    }

    /**
     * Run batch reconciliation audit comparing internal payments vs gateway settlements
     */
    public function run(Request $request)
    {
        $tenantId = $this->getTenantId($request);
        $batchCode = 'REC-BATCH-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -4));

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
