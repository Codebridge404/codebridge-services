<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\StoreBankAccountRequest;
use App\Http\Requests\Finance\StoreExpenseRequest;
use App\Http\Requests\Finance\StoreIncomeRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * [BD-0096] Cash Flow, Revenue Accounts & Financial Ledger Management Controller
 *
 * @ticket BD-0096
 * @component operations-service
 * @file codebridge-services/operations-service/app/Http/Controllers/Api/V1/FinanceController.php
 * @related-model DB table ledger_entries, App\Models\Shift
 * @entry-point FinanceController::index(), recordCashMovement(), dailySummary()
 * @routes
 *  - GET  /api/v1/finance/ledger
 *  - POST /api/v1/finance/cash-movement
 *  - GET  /api/v1/finance/daily-summary
 * @date-time 2026-10-23T15:40:00+07:00
 *
 * Process Capabilities:
 * Step 1 (Tenant Ledger Scoping): Enforces tenant financial data isolation across chart of accounts.
 * Step 2 (Cash Drawer In/Out Recording): Records cash drops, float additions, and petty cash payouts with cashier reason notes.
 * Step 3 (Revenue Account Mapping): Maps checkout payment methods (Cash, Card, Bakong KHQR) to financial accounts.
 * Step 4 (Daily Summary Generation): Generates end-of-day revenue summaries and cash-on-hand audit totals.
 * Step 5 (Financial Export): Exports ledger entries to standardized CSV/accounting formats for bookkeeping.
 */
class FinanceController extends Controller
{
    use \App\Traits\ResolvesTenantContext;

    public function expenses(Request $request)
    {
        $tenantId = $this->getTenantId($request, true);
        $perPage = max(1, min(100, (int) $request->query('per_page', 50)));
        $query = DB::table('expenses');

        if ($tenantId !== 'all') {
            $query->where('tenant_id', $tenantId);
        }

        $paginator = $query->orderBy('date_paid', 'desc')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data' => $paginator->items(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function storeExpense(StoreExpenseRequest $request)
    {
        $tenantId = $this->getTenantId($request, true);
        $validated = $request->validated();

        $ref = $validated['expense_ref'] ?? ('EXP-' . strtoupper(substr(uniqid(), -6)));

        $id = DB::table('expenses')->insertGetId([
            'tenant_id' => $tenantId === 'all' ? null : $tenantId,
            'expense_ref' => $ref,
            'category' => $validated['category'],
            'description' => $validated['description'],
            'amount' => $validated['amount'],
            'date_paid' => $validated['date_paid'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $expense = DB::table('expenses')->where('id', $id)->first();

        return response()->json([
            'status' => 'success',
            'message' => 'Expense recorded successfully',
            'data' => $expense,
        ], 201);
    }

    public function incomes(Request $request)
    {
        $tenantId = $this->getTenantId($request, true);
        $perPage = max(1, min(100, (int) $request->query('per_page', 50)));
        $query = DB::table('incomes');

        if ($tenantId !== 'all') {
            $query->where('tenant_id', $tenantId);
        }

        $paginator = $query->orderBy('date_received', 'desc')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data' => $paginator->items(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function storeIncome(StoreIncomeRequest $request)
    {
        $tenantId = $this->getTenantId($request, true);
        $validated = $request->validated();

        $ref = $validated['income_ref'] ?? ('INC-' . strtoupper(substr(uniqid(), -6)));

        $id = DB::table('incomes')->insertGetId([
            'tenant_id' => $tenantId === 'all' ? null : $tenantId,
            'income_ref' => $ref,
            'source' => $validated['source'],
            'description' => $validated['description'],
            'amount' => $validated['amount'],
            'date_received' => $validated['date_received'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $income = DB::table('incomes')->where('id', $id)->first();

        return response()->json([
            'status' => 'success',
            'message' => 'Income recorded successfully',
            'data' => $income,
        ], 201);
    }

    public function bankAccounts(Request $request)
    {
        $tenantId = $this->getTenantId($request, true);
        $perPage = max(1, min(100, (int) $request->query('per_page', 50)));
        $query = DB::table('bank_accounts');

        if ($tenantId !== 'all') {
            $query->where('tenant_id', $tenantId);
        }

        $paginator = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data' => $paginator->items(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function storeBankAccount(StoreBankAccountRequest $request)
    {
        $tenantId = $this->getTenantId($request, true);
        $validated = $request->validated();

        $id = DB::table('bank_accounts')->insertGetId([
            'tenant_id' => $tenantId === 'all' ? null : $tenantId,
            'bank_name' => $validated['bank_name'],
            'account_name' => $validated['account_name'],
            'account_number' => $validated['account_number'],
            'currency' => $validated['currency'] ?? 'USD',
            'status' => $validated['status'] ?? 'connected',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $account = DB::table('bank_accounts')->where('id', $id)->first();

        return response()->json([
            'status' => 'success',
            'message' => 'Bank account connected successfully',
            'data' => $account,
        ], 201);
    }
}
