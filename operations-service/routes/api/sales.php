<?php

use App\Http\Controllers\Api\V1\CartController;
use App\Http\Controllers\Api\V1\CheckoutController;
use App\Http\Controllers\Api\V1\KdsController;
use App\Http\Controllers\Api\V1\OfflineSyncController;
use App\Http\Controllers\Api\V1\OnlineOrderController;
use App\Http\Controllers\Api\V1\ReceiptController;
use App\Http\Controllers\Api\V1\RefundController;
use App\Http\Controllers\Api\V1\TableController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public E-Commerce Online Order Creation
|--------------------------------------------------------------------------
*/
Route::post('/online-orders', [OnlineOrderController::class, 'store']);

/*
|--------------------------------------------------------------------------
| POS Checkout, Receipts, Returns, KDS, & Tables (Protected)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {
    Route::middleware('role:cashier,supervisor,outlet_manager,admin,super_admin,accountant,inventory_clerk,user,employee,organization_owner,owner')->group(function () {
        Route::get('/sales', [CheckoutController::class, 'index']);
        Route::post('/sales', [CheckoutController::class, 'store']);
        Route::post('/sales/sync', [OfflineSyncController::class, 'sync']);
        Route::post('/offline-sync', [OfflineSyncController::class, 'sync']);
        Route::get('/offline-sync/conflicts', [OfflineSyncController::class, 'conflicts']);
        Route::get('/offline-sync/conflicts/{id}', [OfflineSyncController::class, 'showConflict']);
        Route::patch('/offline-sync/conflicts/{id}/resolve', [OfflineSyncController::class, 'resolveConflict']);
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

    // Cart Parking & Resuming
    Route::post('/carts/hold', [CartController::class, 'hold']);
    Route::get('/carts/held', [CartController::class, 'held']);
    Route::post('/carts/held/{id}/resume', [CartController::class, 'resume']);
    Route::delete('/carts/held/{id}', [CartController::class, 'destroy']);
});
