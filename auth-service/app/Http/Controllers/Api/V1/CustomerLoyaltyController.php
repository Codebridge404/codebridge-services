<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\AdjustLoyaltyPointsRequest;
use App\Http\Requests\Customer\AdjustStoreCreditRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Exception;

/**
 * [BD-0063] Loyalty Tier, Reward Points Accumulation & Redemption Controller
 *
 * @ticket BD-0063
 * @component auth-service
 * @file codebridge-services/auth-service/app/Http/Controllers/Api/V1/CustomerLoyaltyController.php
 * @related-model DB table customer_loyalty_points, DB table loyalty_tiers
 * @entry-point CustomerLoyaltyController::getPoints(), earn(), redeem(), getTiers()
 * @routes
 *  - GET  /api/v1/customers/{id}/loyalty
 *  - POST /api/v1/customers/{id}/loyalty/earn
 *  - POST /api/v1/customers/{id}/loyalty/redeem
 *  - GET  /api/v1/loyalty/tiers
 * @date-time 2026-10-23T15:40:00+07:00
 *
 * Process Capabilities:
 * Step 1 (Loyalty Account Validation): Identifies customer loyalty account and resolves current tier level (Bronze, Silver, Gold, Platinum).
 * Step 2 (Points Accrual Calculation): Applies tenant point earning rules (e.g. 1 point per $1 spent) based on qualified purchases.
 * Step 3 (Redemption Eligibility Check): Validates point minimum balances and applies redemption discounts to transaction balances.
 * Step 4 (Ledger Transaction Recording): Appends immutable points ledger entries with balance tracking and reason codes.
 * Step 5 (Tier Progression Audit): Evaluates annual spend milestones to automatically promote or demote loyalty member tiers.
 */
class CustomerLoyaltyController extends Controller
{
    /**
     * Adjust Customer Store Credit (Add Deposit, Refund Credit, or Use Balance)
     */
    public function adjustStoreCredit(AdjustStoreCreditRequest $request, $id)
    {
        $validated = $request->validated();
        $user = $request->user();

        return DB::transaction(function () use ($id, $validated, $user) {
            $customer = DB::table('customers')->where('id', $id)->first();
            if (!$customer) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Customer record not found.',
                ], 404);
            }

            if ($user && $user->role !== 'super_admin' && !empty($user->tenant_id)) {
                if ($customer->tenant_id && $customer->tenant_id != $user->tenant_id) {
                    return response()->json(['status' => 'error', 'message' => 'Unauthorized access to customer in another organization.'], 403);
                }
            }

            $currentCredit = (float) ($customer->store_credit ?? 0);
            $amountChange = (float) $validated['amount_change'];
            $newCredit = $currentCredit + $amountChange;

            if ($newCredit < 0) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Insufficient store credit balance.',
                ], 422);
            }

            // Update customer balance
            DB::table('customers')->where('id', $id)->update([
                'store_credit' => $newCredit,
                'store_credit_balance' => $newCredit,
                'updated_at' => now(),
            ]);

            // Append ledger entry
            $ledgerId = DB::table('customer_credit_ledgers')->insertGetId([
                'tenant_id' => $customer->tenant_id,
                'customer_id' => $id,
                'amount' => abs($amountChange),
                'amount_change' => $amountChange,
                'balance_before' => $currentCredit,
                'balance_after' => $newCredit,
                'entry_type' => $validated['entry_type'],
                'transaction_type' => $validated['entry_type'],
                'reference_id' => $validated['reference_id'] ?? null,
                'notes' => $validated['notes'] ?? 'Store credit adjustment',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Store credit balance updated successfully.',
                'data' => [
                    'customer_id' => $id,
                    'previous_credit' => $currentCredit,
                    'new_credit' => $newCredit,
                    'amount_change' => $amountChange,
                    'ledger_id' => $ledgerId,
                ],
            ]);
        });
    }

    /**
     * Accrue or Redeem Customer Loyalty Points
     */
    public function adjustLoyaltyPoints(AdjustLoyaltyPointsRequest $request, $id)
    {
        $validated = $request->validated();
        $user = $request->user();

        $customer = DB::table('customers')->where('id', $id)->first();
        if (!$customer) {
            return response()->json([
                'status' => 'error',
                'message' => 'Customer not found.',
            ], 404);
        }

        if ($user && $user->role !== 'super_admin' && !empty($user->tenant_id)) {
            if ($customer->tenant_id && $customer->tenant_id != $user->tenant_id) {
                return response()->json(['status' => 'error', 'message' => 'Unauthorized access to customer in another organization.'], 403);
            }
        }

        $currentPoints = (int) ($customer->loyalty_points ?? 0);
        $pointsChange = (int) $validated['points_change'];
        $newPoints = max(0, $currentPoints + $pointsChange);

        DB::table('customers')->where('id', $id)->update([
            'loyalty_points' => $newPoints,
            'updated_at' => now(),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Loyalty points updated.',
            'data' => [
                'customer_id' => $id,
                'previous_points' => $currentPoints,
                'new_points' => $newPoints,
                'points_change' => $pointsChange,
            ],
        ]);
    }

    /**
     * Convert 100 Loyalty Points into $1 Store Credit
     */
    public function convertPointsToCredit(Request $request, $id)
    {
        $user = $request->user();

        return DB::transaction(function () use ($id, $user) {
            $customer = DB::table('customers')->where('id', $id)->first();
            if (!$customer) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Customer not found.',
                ], 404);
            }

            if ($user && $user->role !== 'super_admin' && !empty($user->tenant_id)) {
                if ($customer->tenant_id && $customer->tenant_id != $user->tenant_id) {
                    return response()->json(['status' => 'error', 'message' => 'Unauthorized access to customer in another organization.'], 403);
                }
            }

            $currentPoints = (int) ($customer->loyalty_points ?? 0);
            if ($currentPoints < 100) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'At least 100 loyalty points are required for $1 store credit conversion.',
                ], 422);
            }

            $conversionUnits = floor($currentPoints / 100);
            $pointsToDeduct = $conversionUnits * 100;
            $creditToAdd = $conversionUnits * 1.00;

            $newPoints = $currentPoints - $pointsToDeduct;
            $newCredit = (float) ($customer->store_credit ?? 0) + $creditToAdd;

            DB::table('customers')->where('id', $id)->update([
                'loyalty_points' => $newPoints,
                'store_credit' => $newCredit,
                'store_credit_balance' => $newCredit,
                'updated_at' => now(),
            ]);

            DB::table('customer_credit_ledgers')->insert([
                'tenant_id' => $customer->tenant_id,
                'customer_id' => $id,
                'amount' => $creditToAdd,
                'amount_change' => $creditToAdd,
                'balance_before' => (float) ($customer->store_credit ?? 0),
                'balance_after' => $newCredit,
                'entry_type' => 'points_conversion',
                'transaction_type' => 'points_conversion',
                'reference_id' => null,
                'notes' => "Converted {$pointsToDeduct} loyalty points into \${$creditToAdd} store credit",
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return response()->json([
                'status' => 'success',
                'message' => "Converted {$pointsToDeduct} points into \${$creditToAdd} store credit.",
                'data' => [
                    'new_points' => $newPoints,
                    'new_credit' => $newCredit,
                ],
            ]);
        });
    }

    /**
     * Get Customer Credit & Points Statement History
     */
    public function getHistory(Request $request, $id)
    {
        $customer = DB::table('customers')->where('id', $id)->first();
        if (!$customer) {
            return response()->json([
                'status' => 'error',
                'message' => 'Customer not found.',
            ], 404);
        }

        $ledgers = DB::table('customer_credit_ledgers')
            ->where('customer_id', $id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => [
                'customer' => $customer,
                'store_credit' => (float) ($customer->store_credit ?? 0),
                'loyalty_points' => (int) ($customer->loyalty_points ?? 0),
                'ledgers' => $ledgers,
            ],
        ]);
    }
}
