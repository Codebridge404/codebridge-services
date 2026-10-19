<?php

use App\Http\Controllers\Api\V1\FinanceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Corporate Finance, Expenses & Bank Accounts (Protected)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/expenses', [FinanceController::class, 'expenses']);
    Route::post('/expenses', [FinanceController::class, 'storeExpense']);
    Route::get('/income', [FinanceController::class, 'incomes']);
    Route::post('/income', [FinanceController::class, 'storeIncome']);
    Route::get('/bank-accounts', [FinanceController::class, 'bankAccounts']);
    Route::post('/bank-accounts', [FinanceController::class, 'storeBankAccount']);
});
