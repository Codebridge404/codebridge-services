<?php

use App\Http\Controllers\Api\V1\AttendanceController;
use App\Http\Controllers\Api\V1\CrmController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| CRM (Customer Relationship Management)
|--------------------------------------------------------------------------
*/
Route::get('/crm/deals', [CrmController::class, 'deals']);
Route::post('/crm/deals', [CrmController::class, 'storeDeal']);
Route::put('/crm/deals/{id}', [CrmController::class, 'updateDeal']);
Route::delete('/crm/deals/{id}', [CrmController::class, 'destroyDeal']);

Route::get('/crm/leads', [CrmController::class, 'leads']);
Route::post('/crm/leads', [CrmController::class, 'storeLead']);

Route::get('/crm/activities', [CrmController::class, 'activities']);
Route::post('/crm/activities', [CrmController::class, 'storeActivity']);
Route::get('/crm/kpis', [CrmController::class, 'kpis']);

/*
|--------------------------------------------------------------------------
| Attendance & Staff Clock-In/Clock-Out
|--------------------------------------------------------------------------
*/
Route::get('/attendance', [AttendanceController::class, 'index']);
Route::post('/attendance/clock-in', [AttendanceController::class, 'clockIn']);
Route::post('/attendance/clock-out', [AttendanceController::class, 'clockOut']);
