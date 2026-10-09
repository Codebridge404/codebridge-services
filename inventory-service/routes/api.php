<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\InventoryController;
use App\Http\Controllers\Api\V1\TransferController;
use App\Http\Controllers\Api\V1\PurchaseController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\ProductExportController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\BrandController;
use App\Http\Controllers\Api\V1\CouponController;
use App\Http\Controllers\Api\V1\DiscountController;

Route::prefix('v1')->group(function () {
    // Health Check Endpoint for Inventory Microservice
    Route::get('/health', function () {
        return response()->json([
            'status' => 'healthy',
            'service' => 'inventory-service',
            'timestamp' => now()->toIso8601String(),
        ]);
    });

    // Public / Read-Only Catalog Endpoints
    Route::get('/products', [ProductController::class, 'index']);
    Route::get('/products/export', [ProductExportController::class, 'export']);
    Route::get('/products/{id}', [ProductController::class, 'show']);
    Route::get('/barcodes/{code}', [ProductController::class, 'showByBarcode']);
    Route::get('/categories', [CategoryController::class, 'index']);
    Route::get('/brands', [BrandController::class, 'index']);
    Route::get('/discounts', [DiscountController::class, 'index']);
    Route::post('/coupons/validate', [CouponController::class, 'validateCoupon']);

    // Protected Operations
    Route::middleware('auth:sanctum')->group(function () {
        // Read-only stock queries accessible by all authenticated organization staff
        Route::get('/inventory/balances', [InventoryController::class, 'balances']);
        Route::get('/inventory/movements', [InventoryController::class, 'movements']);
        Route::get('/inventory/expired', [InventoryController::class, 'expired']);
        Route::get('/inventory/transfers', [TransferController::class, 'index']);
        Route::get('/inventory/transfers/{id}', [TransferController::class, 'show']);
        Route::get('/purchases', [PurchaseController::class, 'purchases']);
        Route::get('/purchase-orders', [PurchaseController::class, 'purchaseOrders']);

        // Stock Mutations (Write Operations - Guarded by Manager/Clerk Roles)
        Route::middleware('role:admin,super_admin,outlet_manager,inventory_clerk')->group(function () {
            Route::post('/inventory/receive', [InventoryController::class, 'receive']);
            Route::post('/inventory/adjust', [InventoryController::class, 'adjust']);
            Route::post('/inventory/transfers', [TransferController::class, 'store']);
            Route::post('/inventory/transfers/{id}/receive', [TransferController::class, 'receive']);

            // Purchase Orders Workflow Mutations
            Route::post('/purchase-orders', [PurchaseController::class, 'store']);
            Route::patch('/purchase-orders/{id}/status', [PurchaseController::class, 'updateStatus']);
            Route::post('/purchase-orders/{id}/receive', [PurchaseController::class, 'receive']);

            // Product Management Mutations
            Route::post('/products', [ProductController::class, 'store']);
            Route::post('/products/bulk', [ProductController::class, 'bulkStore']);
            Route::put('/products/{id}', [ProductController::class, 'update']);
            Route::delete('/products/{id}', [ProductController::class, 'destroy']);

            // Categories Management
            Route::post('/categories', [CategoryController::class, 'store']);
            Route::put('/categories/{id}', [CategoryController::class, 'update']);
            Route::delete('/categories/{id}', [CategoryController::class, 'destroy']);

            // Brands Management
            Route::post('/brands', [BrandController::class, 'store']);
            Route::put('/brands/{id}', [BrandController::class, 'update']);
            Route::delete('/brands/{id}', [BrandController::class, 'destroy']);
        });
    });
});
