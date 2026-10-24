<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\DigitalVoucher;
use App\Models\StoreCreditInvoice;
use App\Services\StoreCreditService;
use Dedoc\Scramble\Attributes\Group;
use Exception;
use Illuminate\Http\Request;

/**
 * [BD-0035] Customer Store Credit Invoicing, Voucher Redemption & Wallet APIs.
 *
 * @ticket BD-0035
 * @component auth-service
 * @file codebridge-services/auth-service/app/Http/Controllers/Api/V1/StoreCreditInvoiceController.php
 * @related-service App\Services\StoreCreditService
 * @entry-point API Routes: /api/v1/customers/{id}/credit/* & /api/v1/vouchers/*
 * @date-time 2026-10-23T15:35:00+07:00
 *
 * Capabilities:
 * - Issue atomic top-up invoices with cash/card/KHQR tender.
 * - Issue and redeem promotional digital gift vouchers.
 * - Real-time customer balance statements & upcoming 30-day expiration tracking.
 * - Manual trigger endpoint for expiration sweeps.
 *
 * @tags Customer Store Credit & Vouchers
 */
#[Group('Customer Store Credit & Vouchers')]
class StoreCreditInvoiceController extends Controller
{
    public function __construct(
        protected StoreCreditService $storeCreditService
    ) {}

    protected function getTenantId(Request $request): ?string
    {
        return $request->header('X-Tenant-Id')
            ?? $request->user()?->tenant_id
            ?? $request->query('tenant_id')
            ?? null;
    }

    /**
     * Issue Customer Store Credit Top-Up Invoice.
     * POST /api/v1/customers/{id}/credit/topup-invoice
     */
    public function topUp(Request $request, $id)
    {
        $validated = $request->validate([
            'amount_paid' => 'required|numeric|min:0.01',
            'bonus_credit' => 'nullable|numeric|min:0',
            'payment_method' => 'nullable|string|in:cash,card,khqr,transfer',
            'payment_reference' => 'nullable|string|max:100',
            'validity_days' => 'nullable|integer|min:1|max:1825', // up to 5 years
            'notes' => 'nullable|string|max:255',
            'outlet_id' => 'nullable|integer',
        ]);

        $customer = Customer::find($id);
        if (!$customer) {
            return response()->json([
                'status' => 'error',
                'message' => 'Customer not found.',
            ], 404);
        }

        $tenantId = $this->getTenantId($request);

        $result = $this->storeCreditService->createTopUpInvoice(
            customer: $customer,
            amountPaid: (float) $validated['amount_paid'],
            bonusCredit: (float) ($validated['bonus_credit'] ?? 0.00),
            paymentMethod: $validated['payment_method'] ?? 'cash',
            paymentReference: $validated['payment_reference'] ?? null,
            validityDays: isset($validated['validity_days']) ? (int) $validated['validity_days'] : null,
            notes: $validated['notes'] ?? null,
            actor: $request->user(),
            outletId: $validated['outlet_id'] ?? null,
            tenantId: $tenantId
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Store credit top-up invoice issued and credited successfully.',
            'data' => $result,
        ], 201);
    }

    /**
     * List Top-Up Invoices for a customer.
     * GET /api/v1/customers/{id}/credit/invoices
     */
    public function customerInvoices(Request $request, $id)
    {
        $customer = Customer::find($id);
        if (!$customer) {
            return response()->json(['status' => 'error', 'message' => 'Customer not found.'], 404);
        }

        $invoices = StoreCreditInvoice::where('customer_id', $id)
            ->orderBy('id', 'desc')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => [
                'customer_id' => $customer->id,
                'invoices' => $invoices,
            ],
        ]);
    }

    /**
     * Get single Store Credit Invoice details.
     * GET /api/v1/customers/credit/invoices/{invoiceId}
     */
    public function showInvoice(Request $request, $invoiceId)
    {
        $invoice = StoreCreditInvoice::with(['customer', 'cashier'])
            ->where('id', $invoiceId)
            ->orWhere('invoice_number', $invoiceId)
            ->first();

        if (!$invoice) {
            return response()->json(['status' => 'error', 'message' => 'Store credit invoice not found.'], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $invoice,
        ]);
    }

    /**
     * Issue a new digital gift voucher.
     * POST /api/v1/vouchers/issue
     */
    public function issueVoucher(Request $request)
    {
        $validated = $request->validate([
            'value' => 'required|numeric|min:0.01',
            'title' => 'nullable|string|max:100',
            'validity_days' => 'nullable|integer|min:1|max:730',
            'customer_id' => 'nullable|integer|exists:customers,id',
            'notes' => 'nullable|string|max:255',
        ]);

        $tenantId = $this->getTenantId($request);

        $voucher = $this->storeCreditService->issueDigitalVoucher(
            value: (float) $validated['value'],
            title: $validated['title'] ?? 'Customer Gift Voucher',
            validityDays: $validated['validity_days'] ?? 90,
            customerId: $validated['customer_id'] ?? null,
            actor: $request->user(),
            tenantId: $tenantId,
            notes: $validated['notes'] ?? null
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Digital voucher issued successfully.',
            'data' => $voucher,
        ], 201);
    }

    /**
     * Redeem a digital voucher.
     * POST /api/v1/vouchers/redeem
     */
    public function redeemVoucher(Request $request)
    {
        $validated = $request->validate([
            'voucher_code' => 'required|string',
            'customer_id' => 'required|integer|exists:customers,id',
        ]);

        $customer = Customer::find($validated['customer_id']);

        try {
            $result = $this->storeCreditService->redeemDigitalVoucher(
                voucherCode: $validated['voucher_code'],
                customer: $customer,
                actor: $request->user()
            );

            return response()->json([
                'status' => 'success',
                'message' => 'Digital voucher redeemed successfully and credited to wallet.',
                'data' => $result,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * List digital vouchers.
     * GET /api/v1/vouchers
     */
    public function vouchers(Request $request)
    {
        $tenantId = $this->getTenantId($request);
        $status = $request->query('status');
        $customerId = $request->query('customer_id');

        $query = DigitalVoucher::query();

        if (!empty($tenantId) && $tenantId !== 'all') {
            $query->where('tenant_id', $tenantId);
        }
        if (!empty($status)) {
            $query->where('status', $status);
        }
        if (!empty($customerId)) {
            $query->where(function ($q) use ($customerId) {
                $q->where('issued_to_customer_id', $customerId)
                  ->orWhere('redeemed_by_customer_id', $customerId);
            });
        }

        $vouchers = $query->orderBy('created_at', 'desc')->get();

        return response()->json([
            'status' => 'success',
            'data' => $vouchers,
        ]);
    }

    /**
     * Run store credit & digital voucher expirations sweep.
     * POST /api/v1/customers/credit/process-expirations
     */
    public function expireNow(Request $request)
    {
        $tenantId = $this->getTenantId($request);
        $summary = $this->storeCreditService->processExpirations($tenantId);

        return response()->json([
            'status' => 'success',
            'message' => 'Store credit expiration sweep executed.',
            'data' => $summary,
        ]);
    }

    /**
     * Get Customer Debit / Credit Ledger Balance Statement.
     * GET /api/v1/customers/{id}/credit/statement
     */
    public function statement(Request $request, $id)
    {
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');

        $statement = $this->storeCreditService->getCustomerStatement((int) $id, $startDate, $endDate);

        if (!$statement) {
            return response()->json(['status' => 'error', 'message' => 'Customer not found.'], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $statement,
        ]);
    }
}
