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
| Catalog Management Mutations & Exports (Protected Staff & Manager Group)
|--------------------------------------------------------------------------
| Note: Declared first so static routes like /products/export take precedence
| over parameterized wildcard routes like /products/{id}.
*/
Route::middleware(['auth:sanctum', 'role:organization,admin,super_admin,outlet_manager,inventory_clerk'])->group(function () {
    // Product Catalog Export (Tenant Scoped)
    Route::get('/products/export', [ProductExportController::class, 'export']);

    // Products CRUD
    Route::post('/products', [ProductController::class, 'store']);
    Route::post('/products/bulk', [ProductController::class, 'bulkStore']);
    Route::post('/products/{id}/image', [ProductController::class, 'uploadImage'])->whereNumber('id');
    Route::post('/products/{id}', [ProductController::class, 'update'])->whereNumber('id');
    Route::put('/products/{id}', [ProductController::class, 'update'])->whereNumber('id');
    Route::delete('/products/{id}', [ProductController::class, 'destroy'])->whereNumber('id');

    // Categories CRUD
    Route::post('/categories', [CategoryController::class, 'store']);
    Route::put('/categories/{id}', [CategoryController::class, 'update'])->whereNumber('id');
    Route::delete('/categories/{id}', [CategoryController::class, 'destroy'])->whereNumber('id');

    // Brands CRUD
    Route::post('/brands', [BrandController::class, 'store']);
    Route::put('/brands/{id}', [BrandController::class, 'update'])->whereNumber('id');
    Route::delete('/brands/{id}', [BrandController::class, 'destroy'])->whereNumber('id');
});

/*
|--------------------------------------------------------------------------
| Public Catalog & Pricing Endpoints (Storefront & Read-Only)
|--------------------------------------------------------------------------
*/
Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/{id}', [ProductController::class, 'show'])->whereNumber('id');
Route::get('/barcodes/{code}', [ProductController::class, 'showByBarcode']);
Route::get('/categories', [CategoryController::class, 'index']);
Route::get('/brands', [BrandController::class, 'index']);
Route::get('/discounts', [DiscountController::class, 'index']);
Route::post('/coupons/validate', [CouponController::class, 'validateCoupon']);
