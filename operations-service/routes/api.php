<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Inventory & Catalog Controllers
use App\Http\Controllers\Api\V1\InventoryController;
use App\Http\Controllers\Api\V1\TransferController;
use App\Http\Controllers\Api\V1\PurchaseController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\ProductExportController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\BrandController;
use App\Http\Controllers\Api\V1\CouponController;
use App\Http\Controllers\Api\V1\DiscountController;

// Commerce, Sales, Shift & Finance Controllers
use App\Http\Controllers\Api\V1\CheckoutController;
use App\Http\Controllers\Api\V1\CartController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\ReceiptController;
use App\Http\Controllers\Api\V1\RefundController;
use App\Http\Controllers\Api\V1\OnlineOrderController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\OfflineSyncController;
use App\Http\Controllers\Api\V1\KdsController;
use App\Http\Controllers\Api\V1\TableController;
use App\Http\Controllers\Api\V1\ShiftController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\ReconciliationController;
use App\Http\Controllers\Api\V1\FinanceController;

Route::prefix('v1')->group(function () {
    // Health Check Endpoint for Operations Microservice
    Route::get('/health', function () {
        return response()->json([
            'status' => 'healthy',
            'service' => 'operations-service',
            'timestamp' => now()->toIso8601String(),
        ]);
    });

    // =========================================================================
    // 1. Public Endpoints (Catalog, Online Orders, Bank Webhooks)
    // =========================================================================
    Route::get('/products', [ProductController::class, 'index']);
    Route::get('/products/export', [ProductExportController::class, 'export']);
    Route::get('/products/{id}', [ProductController::class, 'show']);
    Route::get('/barcodes/{code}', [ProductController::class, 'showByBarcode']);
    Route::get('/categories', [CategoryController::class, 'index']);
    Route::get('/brands', [BrandController::class, 'index']);
    Route::get('/discounts', [DiscountController::class, 'index']);
    Route::post('/coupons/validate', [CouponController::class, 'validateCoupon']);

    // Public E-Commerce Order Creation
    Route::post('/online-orders', [OnlineOrderController::class, 'store']);

    // Public Webhook Callbacks (Bakong / ABA PayWay / KHQR)
    Route::post('/payment-callbacks/aba', function (Request $request) {
        return response()->json([
            'status' => 'success',
            'message' => 'Payment callback received',
            'data' => $request->all(),
        ]);
    });

    // =========================================================================
    // 2. Protected Operations (Requires Sanctum Bearer Token)
    // =========================================================================
    Route::middleware('auth:sanctum')->group(function () {
        // --- Inventory & Stock Queries ---
        Route::get('/inventory/balances', [InventoryController::class, 'balances']);
        Route::get('/inventory/movements', [InventoryController::class, 'movements']);
        Route::get('/inventory/expired', [InventoryController::class, 'expired']);
        Route::get('/inventory/transfers', [TransferController::class, 'index']);
        Route::get('/inventory/transfers/{id}', [TransferController::class, 'show']);
        Route::get('/purchases', [PurchaseController::class, 'purchases']);
        Route::get('/purchase-orders', [PurchaseController::class, 'purchaseOrders']);

        // --- Stock Mutations (Manager/Clerk) ---
        Route::middleware('role:admin,super_admin,outlet_manager,inventory_clerk')->group(function () {
            Route::post('/inventory/receive', [InventoryController::class, 'receive']);
            Route::post('/inventory/adjust', [InventoryController::class, 'adjust']);
            Route::post('/inventory/transfers', [TransferController::class, 'store']);
            Route::post('/inventory/transfers/{id}/receive', [TransferController::class, 'receive']);

            Route::post('/purchase-orders', [PurchaseController::class, 'store']);
            Route::patch('/purchase-orders/{id}/status', [PurchaseController::class, 'updateStatus']);
            Route::post('/purchase-orders/{id}/receive', [PurchaseController::class, 'receive']);

            Route::post('/products', [ProductController::class, 'store']);
            Route::post('/products/bulk', [ProductController::class, 'bulkStore']);
            Route::put('/products/{id}', [ProductController::class, 'update']);
            Route::delete('/products/{id}', [ProductController::class, 'destroy']);

            Route::post('/categories', [CategoryController::class, 'store']);
            Route::put('/categories/{id}', [CategoryController::class, 'update']);
            Route::delete('/categories/{id}', [CategoryController::class, 'destroy']);

            Route::post('/brands', [BrandController::class, 'store']);
            Route::put('/brands/{id}', [BrandController::class, 'update']);
            Route::delete('/brands/{id}', [BrandController::class, 'destroy']);
        });

        // --- Cash Drawer Shifts & Till Floats ---
        Route::get('/shifts/active', [ShiftController::class, 'active']);
        Route::post('/shifts/open', [ShiftController::class, 'open']);
        Route::get('/shifts/history', [ShiftController::class, 'history']);
        Route::get('/shifts/{id}/x-report', [ShiftController::class, 'xReport']);
        Route::post('/shifts/{id}/cash-movement', [ShiftController::class, 'cashMovement']);
        Route::post('/shifts/{id}/close', [ShiftController::class, 'close']);
        Route::post('/shifts/cash-movement', [ShiftController::class, 'cashMovement']);
        Route::post('/shifts/close', [ShiftController::class, 'close']);

        // --- Payments & Bakong KHQR ---
        Route::post('/payments/khqr/generate', [PaymentController::class, 'generateKhqr']);
        Route::match(['get', 'post'], '/payments/{id}/status', [PaymentController::class, 'checkStatus']);
        Route::post('/payments/{id}/confirm-hash', [PaymentController::class, 'confirmHash']);
        Route::post('/payments/{id}/simulate-pay', [PaymentController::class, 'simulatePay']);

        // --- Payment Reconciliation ---
        Route::post('/reconciliation/run', [ReconciliationController::class, 'run']);
        Route::get('/reconciliation/exceptions', [ReconciliationController::class, 'exceptions']);
        Route::post('/reconciliation/exceptions/{id}/resolve', [ReconciliationController::class, 'resolveException']);

        // --- Corporate Finance, Expenses & Bank Accounts ---
        Route::get('/expenses', [FinanceController::class, 'expenses']);
        Route::post('/expenses', [FinanceController::class, 'storeExpense']);
        Route::get('/income', [FinanceController::class, 'incomes']);
        Route::post('/income', [FinanceController::class, 'storeIncome']);
        Route::get('/bank-accounts', [FinanceController::class, 'bankAccounts']);
        Route::post('/bank-accounts', [FinanceController::class, 'storeBankAccount']);

        // --- POS Checkout, Receipts, Returns, KDS, & Tables ---
        Route::middleware('role:cashier,supervisor,outlet_manager,admin,super_admin,accountant,inventory_clerk,user,employee,organization_owner,owner')->group(function () {
            Route::get('/sales', [CheckoutController::class, 'index']);
            Route::post('/sales', [CheckoutController::class, 'store']);
            Route::post('/sales/sync', [OfflineSyncController::class, 'sync']);
            Route::get('/sales/{id}/receipt', [ReceiptController::class, 'show']);
            Route::post('/sales/{id}/return-quote', [RefundController::class, 'returnQuote']);
            Route::post('/sales/{id}/refund', [RefundController::class, 'refund']);

            Route::get('/kds/tickets', [KdsController::class, 'index']);
            Route::patch('/kds/tickets/{id}/status', [KdsController::class, 'updateStatus']);
            Route::get('/tables', [TableController::class, 'index']);
            Route::post('/tables', [TableController::class, 'store']);
            Route::patch('/tables/{id}/status', [TableController::class, 'updateStatus']);

            Route::get('/online-orders', [OnlineOrderController::class, 'index']);
            Route::patch('/online-orders/{id}/status', [OnlineOrderController::class, 'updateStatus']);
        });

        // --- Cart Parking & Resuming ---
        Route::post('/carts/hold', [CartController::class, 'hold']);
        Route::get('/carts/held', [CartController::class, 'held']);
        Route::post('/carts/held/{id}/resume', [CartController::class, 'resume']);
        Route::delete('/carts/held/{id}', [CartController::class, 'destroy']);

        // --- Executive Dashboard & Reports ---
        Route::middleware('role:admin,super_admin,outlet_manager,accountant,cashier,supervisor,inventory_clerk,customer,user,employee,organization_owner,owner')->group(function () {
            Route::get('/admin/dashboard/summary', [DashboardController::class, 'summary']);
            Route::get('/admin/dashboard/widgets', [DashboardController::class, 'widgets']);
            Route::get('/admin/dashboard/charts', [DashboardController::class, 'charts']);

            Route::get('/reports/sales', [ReportController::class, 'sales']);
            Route::get('/reports/shifts', [ReportController::class, 'shifts']);
            Route::get('/reports/tax', [ReportController::class, 'tax']);
            Route::get('/reports/export', [ReportController::class, 'export']);
        });
    });
});
