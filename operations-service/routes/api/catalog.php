<?php

use App\Http\Controllers\Api\V1\BrandController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\CouponController;
use App\Http\Controllers\Api\V1\DiscountController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\ProductExportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public Catalog & Pricing Endpoints
|--------------------------------------------------------------------------
*/
Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/export', [ProductExportController::class, 'export']);
Route::get('/products/{id}', [ProductController::class, 'show']);
Route::get('/barcodes/{code}', [ProductController::class, 'showByBarcode']);
Route::get('/categories', [CategoryController::class, 'index']);
Route::get('/brands', [BrandController::class, 'index']);
Route::get('/discounts', [DiscountController::class, 'index']);
Route::post('/coupons/validate', [CouponController::class, 'validateCoupon']);

/*
|--------------------------------------------------------------------------
| Catalog Management Mutations (Protected)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'role:admin,super_admin,outlet_manager,inventory_clerk'])->group(function () {
    // Products CRUD
    Route::post('/products', [ProductController::class, 'store']);
    Route::post('/products/bulk', [ProductController::class, 'bulkStore']);
    Route::put('/products/{id}', [ProductController::class, 'update']);
    Route::delete('/products/{id}', [ProductController::class, 'destroy']);

    // Categories CRUD
    Route::post('/categories', [CategoryController::class, 'store']);
    Route::put('/categories/{id}', [CategoryController::class, 'update']);
    Route::delete('/categories/{id}', [CategoryController::class, 'destroy']);

    // Brands CRUD
    Route::post('/brands', [BrandController::class, 'store']);
    Route::put('/brands/{id}', [BrandController::class, 'update']);
    Route::delete('/brands/{id}', [BrandController::class, 'destroy']);
});
