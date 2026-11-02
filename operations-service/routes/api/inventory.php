<?php

use App\Http\Controllers\Api\V1\InventoryController;
use App\Http\Controllers\Api\V1\PurchaseController;
use App\Http\Controllers\Api\V1\TransferController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Inventory Stock Queries (Protected)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/inventory/balances', [InventoryController::class, 'balances']);
    Route::get('/inventory/nearby-outlets', [InventoryController::class, 'crossOutletStock']);
    Route::get('/inventory/cross-outlet-stock', [InventoryController::class, 'crossOutletStock']);
    Route::get('/inventory/movements', [InventoryController::class, 'movements']);
    Route::get('/inventory/expired', [InventoryController::class, 'expired']);
    Route::get('/inventory/transfers', [TransferController::class, 'index']);
    Route::get('/inventory/transfers/{id}', [TransferController::class, 'show']);
    Route::get('/purchases', [PurchaseController::class, 'purchases']);
    Route::get('/purchase-orders', [PurchaseController::class, 'purchaseOrders']);
    Route::get('/purchase-orders/reorder-suggestions', [PurchaseController::class, 'reorderSuggestions']);

    // Stock Mutations (Manager/Clerk)
    Route::middleware('role:organization,admin,super_admin,outlet_manager,inventory_clerk')->group(function () {
        Route::post('/inventory/receive', [InventoryController::class, 'receive']);
        Route::post('/inventory/adjust', [InventoryController::class, 'adjust']);
        Route::post('/inventory/transfers', [TransferController::class, 'store']);
        Route::post('/inventory/transfers/{id}/receive', [TransferController::class, 'receive']);
        Route::patch('/inventory/transfers/{id}/dispute', [TransferController::class, 'dispute']);
        Route::patch('/inventory/transfers/{id}/resolve-dispute', [TransferController::class, 'resolveDispute']);

        Route::post('/purchase-orders', [PurchaseController::class, 'store']);
        Route::post('/purchase-orders/auto-reorder', [PurchaseController::class, 'autoReorder']);
        Route::patch('/purchase-orders/{id}/status', [PurchaseController::class, 'updateStatus']);
        Route::post('/purchase-orders/{id}/receive', [PurchaseController::class, 'receive']);
    });
});
