<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Checkout\SplitBillRequest;
use App\Http\Requests\Checkout\StoreCheckoutRequest;
use App\Services\CheckoutService;
use App\Traits\ResolvesTenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Exception;

/**
 * [BD-0012] Server-Authoritative POS Checkout & Idempotency Controller.
 *
 * @ticket BD-0012
 * @component operations-service
 * @file codebridge-services/operations-service/app/Http/Controllers/Api/V1/CheckoutController.php
 * @related-service App\Services\CheckoutService
 * @entry-point POST /api/v1/sales/checkout, GET /api/v1/sales
 * @date-time 2026-10-23T15:40:00+07:00
 */
class CheckoutController extends Controller
{
    use ResolvesTenantContext;

    protected CheckoutService $checkoutService;

    public function __construct(CheckoutService $checkoutService)
    {
        $this->checkoutService = $checkoutService;
    }

    public function index(Request $request)
    {
        $tenantId = $this->getTenantId($request, true);
        $outletId = $request->query('outlet_id');
        $status = $request->query('status');
        $search = $request->query('search');
        $perPage = max(1, min(100, (int) $request->query('per_page', 50)));

        $query = DB::table('sales as s')
            ->leftJoin('users as u', 's.user_id', '=', 'u.id')
            ->leftJoin('payments as p', 's.id', '=', 'p.sale_id')
            ->select(
                's.id',
                's.tenant_id',
                's.receipt_number',
                DB::raw("s.receipt_number as invoice_number"),
                DB::raw("COALESCE(s.customer_name, 'Walk-in Customer') as customer_name"),
                's.customer_id',
                's.table_id',
                's.table_name',
                's.order_type',
                's.subtotal',
                's.tax_total',
                's.discount_total',
                's.grand_total',
                's.status',
                DB::raw("COALESCE(p.tender_type, 'cash') as tender_type"),
                's.created_at',
                'u.name as cashier_name'
            )
            ->orderByDesc('s.created_at');

        if ($tenantId !== 'all') {
            $query->where('s.tenant_id', $tenantId);
        }

        if (!empty($outletId)) {
            $query->where('s.outlet_id', $outletId);
        }

        if (!empty($status) && $status !== 'all') {
            $query->where('s.status', $status);
        }

        if (!empty($search)) {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $search);
            $query->where(function ($q) use ($escaped) {
                $q->where('s.receipt_number', 'like', "%{$escaped}%")
                  ->orWhere('u.name', 'like', "%{$escaped}%");
            });
        }

        $paginator = $query->paginate($perPage);

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

    public function store(StoreCheckoutRequest $request)
    {
        $tenantId = $this->getTenantId($request, true);
        $validated = $request->validated();

        try {
            $result = $this->checkoutService->finalizeSale(
                $validated,
                $request->user(),
                $tenantId === 'all' ? null : $tenantId
            );

            return response()->json([
                'status' => 'success',
                'message' => $result['is_duplicate'] ? 'Sale already processed' : 'Sale completed successfully',
                'data' => $result,
            ], $result['is_duplicate'] ? 200 : 201);
        } catch (Exception $e) {
            $status = str_starts_with($e->getMessage(), 'Unauthorized:') ? 403 : 422;
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], $status);
        }
    }

    /**
     * [BD-0014 / Task #9] Restaurant Table Dine-In Split-Bill API
     */
    public function splitBill(SplitBillRequest $request)
    {
        $tenantId = $this->getTenantId($request, true);
        $validated = $request->validated();

        try {
            $result = $this->checkoutService->processSplitBill(
                $validated,
                $request->user(),
                $tenantId === 'all' ? null : $tenantId
            );

            return response()->json([
                'status' => 'success',
                'message' => $result['message'],
                'data' => $result,
            ], 200);
        } catch (Exception $e) {
            $status = str_starts_with($e->getMessage(), 'Unauthorized:') ? 403 : 400;
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], $status);
        }
    }
}
