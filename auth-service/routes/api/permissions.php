<?php

use App\Http\Controllers\System\PermissionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| System Permissions Catalog
|--------------------------------------------------------------------------
*/
Route::middleware('role:organization,admin,super_admin,outlet_manager')->group(function () {
    Route::get('/permissions', [PermissionController::class, 'index']);
});
