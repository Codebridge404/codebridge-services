<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
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
    // Health Check Endpoint for Sales Microservice
    Route::get('/health', function () {
        return response()->json([
            'status' => 'healthy',
            'service' => 'sales-service',
            'timestamp' => now()->toIso8601String(),
        ]);
    });

    // Public Customer E-commerce Order Creation
    Route::post('/online-orders', [OnlineOrderController::class, 'store']);

    // Public Webhook Callbacks (Bakong / ABA PayWay / KHQR)
    Route::post('/payment-callbacks/aba', function (Request $request) {
        return response()->json([
            'status' => 'success',
            'message' => 'Payment callback received',
            'data' => $request->all(),
        ]);
    });

    // Protected Sales & Order Fulfillment Operations
    Route::middleware('auth:sanctum')->group(function () {
        // Shift & Cash Drawer Operations
        Route::get('/shifts/active', [ShiftController::class, 'active']);
        Route::post('/shifts/open', [ShiftController::class, 'open']);
        Route::get('/shifts/history', [ShiftController::class, 'history']);
        Route::get('/shifts/{id}/x-report', [ShiftController::class, 'xReport']);
        Route::post('/shifts/{id}/cash-movement', [ShiftController::class, 'cashMovement']);
        Route::post('/shifts/{id}/close', [ShiftController::class, 'close']);
        Route::post('/shifts/cash-movement', [ShiftController::class, 'cashMovement']);
        Route::post('/shifts/close', [ShiftController::class, 'close']);

        // Payments & Digital KHQR Processing
        Route::post('/payments/khqr/generate', [PaymentController::class, 'generateKhqr']);
        Route::match(['get', 'post'], '/payments/{id}/status', [PaymentController::class, 'checkStatus']);
        Route::post('/payments/{id}/confirm-hash', [PaymentController::class, 'confirmHash']);
        Route::post('/payments/{id}/simulate-pay', [PaymentController::class, 'simulatePay']);

        // Payment Reconciliation & Exception Auditing
        Route::post('/reconciliation/run', [ReconciliationController::class, 'run']);
        Route::get('/reconciliation/exceptions', [ReconciliationController::class, 'exceptions']);
        Route::post('/reconciliation/exceptions/{id}/resolve', [ReconciliationController::class, 'resolveException']);

        // Expenses, Income & Bank Accounts
        Route::get('/expenses', [FinanceController::class, 'expenses']);
        Route::post('/expenses', [FinanceController::class, 'storeExpense']);
        Route::get('/income', [FinanceController::class, 'incomes']);
        Route::post('/income', [FinanceController::class, 'storeIncome']);
        Route::get('/bank-accounts', [FinanceController::class, 'bankAccounts']);
        Route::post('/bank-accounts', [FinanceController::class, 'storeBankAccount']);

        // Sales Checkout, Receipts, Returns, Refunds, KDS & Tables
        Route::middleware('role:cashier,supervisor,outlet_manager,admin,super_admin,accountant,inventory_clerk,user,employee,organization_owner,owner')->group(function () {
            Route::get('/sales', [CheckoutController::class, 'index']);
            Route::post('/sales', [CheckoutController::class, 'store']);
            Route::post('/sales/sync', [OfflineSyncController::class, 'sync']);
            Route::get('/sales/{id}/receipt', [ReceiptController::class, 'show']);
            Route::post('/sales/{id}/return-quote', [RefundController::class, 'returnQuote']);
            Route::post('/sales/{id}/refund', [RefundController::class, 'refund']);

            // Kitchen Display System (KDS) & Restaurant Tables
            Route::get('/kds/tickets', [KdsController::class, 'index']);
            Route::patch('/kds/tickets/{id}/status', [KdsController::class, 'updateStatus']);
            Route::get('/tables', [TableController::class, 'index']);
            Route::post('/tables', [TableController::class, 'store']);
            Route::patch('/tables/{id}/status', [TableController::class, 'updateStatus']);

            // Online Order Fulfillment Dashboard
            Route::get('/online-orders', [OnlineOrderController::class, 'index']);
            Route::patch('/online-orders/{id}/status', [OnlineOrderController::class, 'updateStatus']);
        });

        // Cart Holding & Resuming
        Route::post('/carts/hold', [CartController::class, 'hold']);
        Route::get('/carts/held', [CartController::class, 'held']);
        Route::post('/carts/held/{id}/resume', [CartController::class, 'resume']);
        Route::delete('/carts/held/{id}', [CartController::class, 'destroy']);

        // Admin Dashboard & Financial Reporting Analytics
        Route::middleware('role:admin,super_admin,outlet_manager,accountant,cashier,supervisor,inventory_clerk,customer,user,employee,organization_owner,owner')->group(function () {
            Route::get('/admin/dashboard/summary', [DashboardController::class, 'summary']);
            Route::get('/admin/dashboard/widgets', [DashboardController::class, 'widgets']);
            Route::get('/admin/dashboard/charts', [DashboardController::class, 'charts']);

            // Financial Reports & CSV Export
            Route::get('/reports/sales', [ReportController::class, 'sales']);
            Route::get('/reports/shifts', [ReportController::class, 'shifts']);
            Route::get('/reports/tax', [ReportController::class, 'tax']);
            Route::get('/reports/export', [ReportController::class, 'export']);
        });
    });
});
