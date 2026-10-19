<?php

use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\ReconciliationController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public Webhook Callbacks (Bakong / ABA PayWay / KHQR)
|--------------------------------------------------------------------------
*/
Route::post('/payment-callbacks/aba', function (Request $request) {
    return response()->json([
        'status' => 'success',
        'message' => 'Payment callback received',
        'data' => $request->all(),
    ]);
});

/*
|--------------------------------------------------------------------------
| Payments & KHQR Processing (Protected)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/payments/khqr/generate', [PaymentController::class, 'generateKhqr']);
    Route::match(['get', 'post'], '/payments/{id}/status', [PaymentController::class, 'checkStatus']);
    Route::post('/payments/{id}/confirm-hash', [PaymentController::class, 'confirmHash']);
    Route::post('/payments/{id}/simulate-pay', [PaymentController::class, 'simulatePay']);

    // Payment Reconciliation
    Route::post('/reconciliation/run', [ReconciliationController::class, 'run']);
    Route::get('/reconciliation/exceptions', [ReconciliationController::class, 'exceptions']);
    Route::post('/reconciliation/exceptions/{id}/resolve', [ReconciliationController::class, 'resolveException']);
});
