<?php

use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\CustomerLoyaltyController;
use App\Http\Controllers\Api\V1\DepartmentController;
use App\Http\Controllers\Api\V1\EmployeeController;
use App\Http\Controllers\Api\V1\GiftCardController;
use App\Http\Controllers\Api\V1\OutletController;
use App\Http\Controllers\Api\V1\StoreCreditInvoiceController;
use App\Http\Controllers\Api\V1\SupplierController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Organization Read-Only Endpoints
|--------------------------------------------------------------------------
*/
Route::get('/outlets', [OutletController::class, 'index']);
Route::get('/departments', [DepartmentController::class, 'index']);
Route::get('/suppliers', [SupplierController::class, 'index']);
Route::get('/customers', [CustomerController::class, 'index']);

/*
|--------------------------------------------------------------------------
| Organization Management & Administrative Mutations
|--------------------------------------------------------------------------
*/
Route::middleware('role:organization,admin,super_admin,outlet_manager')->group(function () {
    Route::get('/audit-logs', [AuditLogController::class, 'index']);
    Route::get('/gift-cards', [GiftCardController::class, 'index']);
    Route::post('/gift-cards', [GiftCardController::class, 'store']);

    // Outlets CRUD & POS Business Profiles
    Route::get('/outlets/business-profiles', [OutletController::class, 'listBusinessProfiles']);
    Route::post('/outlets', [OutletController::class, 'store'])->middleware('quota:outlets');
    Route::put('/outlets/{id}', [OutletController::class, 'update']);
    Route::delete('/outlets/{id}', [OutletController::class, 'destroy']);
    Route::get('/outlets/{id}/terminal-settings', [OutletController::class, 'getTerminalSettings']);
    Route::patch('/outlets/{id}/terminal-settings', [OutletController::class, 'updateTerminalSettings']);
    Route::get('/outlets/{id}/business-profile', [OutletController::class, 'getBusinessProfile']);
    Route::patch('/outlets/{id}/business-profile', [OutletController::class, 'updateBusinessProfile']);
    Route::post('/outlets/{id}/receipt-branding', [OutletController::class, 'updateReceiptBranding']);

    // Departments CRUD
    Route::post('/departments', [DepartmentController::class, 'store']);
    Route::put('/departments/{id}', [DepartmentController::class, 'update']);
    Route::delete('/departments/{id}', [DepartmentController::class, 'destroy']);

    // Suppliers CRUD
    Route::post('/suppliers', [SupplierController::class, 'store']);
    Route::put('/suppliers/{id}', [SupplierController::class, 'update']);
    Route::delete('/suppliers/{id}', [SupplierController::class, 'destroy']);

    // Customers CRUD & Loyalty Program
    Route::post('/customers', [CustomerController::class, 'store']);
    Route::put('/customers/{id}', [CustomerController::class, 'update']);
    Route::delete('/customers/{id}', [CustomerController::class, 'destroy']);
    Route::post('/customers/{id}/store-credit', [CustomerLoyaltyController::class, 'adjustStoreCredit']);
    Route::post('/customers/{id}/loyalty', [CustomerLoyaltyController::class, 'adjustLoyaltyPoints']);
    Route::post('/customers/{id}/convert-points', [CustomerLoyaltyController::class, 'convertPointsToCredit']);
    Route::get('/customers/{id}/history', [CustomerLoyaltyController::class, 'getHistory']);

    // Customer Store Credit Top-Up Invoicing, Statements & Expirations
    Route::post('/customers/{id}/credit/topup-invoice', [StoreCreditInvoiceController::class, 'topUp']);
    Route::get('/customers/{id}/credit/invoices', [StoreCreditInvoiceController::class, 'customerInvoices']);
    Route::get('/customers/credit/invoices/{invoiceId}', [StoreCreditInvoiceController::class, 'showInvoice']);
    Route::get('/customers/{id}/credit/statement', [StoreCreditInvoiceController::class, 'statement']);
    Route::post('/customers/credit/process-expirations', [StoreCreditInvoiceController::class, 'expireNow']);

    // Digital Vouchers Issuing & Redemptions
    Route::get('/vouchers', [StoreCreditInvoiceController::class, 'vouchers']);
    Route::post('/vouchers/issue', [StoreCreditInvoiceController::class, 'issueVoucher']);
    Route::post('/vouchers/redeem', [StoreCreditInvoiceController::class, 'redeemVoucher']);

    // Employees CRUD
    Route::get('/employees', [EmployeeController::class, 'index']);
    Route::post('/employees', [EmployeeController::class, 'store']);
    Route::put('/employees/{id}', [EmployeeController::class, 'update']);
    Route::delete('/employees/{id}', [EmployeeController::class, 'destroy']);
});
