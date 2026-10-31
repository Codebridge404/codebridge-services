<?php

use App\Http\Controllers\Api\V1\MeetingActionItemController;
use App\Http\Controllers\Api\V1\MeetingController;
use App\Http\Controllers\Api\V1\MeetingIssueController;
use App\Http\Controllers\Api\V1\MeetingLogisticsController;
use App\Http\Controllers\Api\V1\MeetingMinutesController;
use App\Http\Controllers\Api\V1\MeetingNotificationController;
use App\Http\Controllers\Api\V1\MeetingRoomController;
use App\Http\Controllers\Api\V1\MeetingSettingController;
use App\Http\Controllers\Api\V1\MeetingTelegramController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Meeting Management Systems (MMS)
|--------------------------------------------------------------------------
*/
Route::get('/meetings/stats', [MeetingController::class, 'stats']);
Route::get('/meetings', [MeetingController::class, 'index']);
Route::post('/meetings', [MeetingController::class, 'store']);
// MMS Governance & Specific Meeting by ID
Route::whereNumber('id')->group(function () {
    Route::get('/meetings/{id}', [MeetingController::class, 'show']);
    Route::put('/meetings/{id}', [MeetingController::class, 'update']);
    Route::delete('/meetings/{id}', [MeetingController::class, 'destroy']);
    Route::post('/meetings/{id}/check-in', [MeetingController::class, 'checkIn']);
    Route::get('/meetings/{id}/ics', [MeetingController::class, 'exportIcs']);

    Route::patch('/meetings/{id}/approve', [MeetingController::class, 'approve']);
    Route::patch('/meetings/{id}/reject', [MeetingController::class, 'reject']);
    Route::patch('/meetings/{id}/end-early', [MeetingController::class, 'endEarly']);
    Route::patch('/meetings/{id}/cancel', [MeetingController::class, 'cancel']);
    Route::patch('/meetings/{id}/rsvp', [MeetingController::class, 'rsvp']);

    Route::get('/meetings/{id}/attachments', [MeetingController::class, 'attachments']);
    Route::post('/meetings/{id}/attachments', [MeetingController::class, 'storeAttachment']);
    Route::delete('/meetings/{id}/attachments/{attachmentId}', [MeetingController::class, 'destroyAttachment']);

    Route::get('/meetings/{id}/minutes', [MeetingMinutesController::class, 'show']);
    Route::post('/meetings/{id}/minutes', [MeetingMinutesController::class, 'storeOrUpdate']);

    Route::get('/meetings/{id}/actions', [MeetingActionItemController::class, 'meetingActions']);
    Route::post('/meetings/{id}/actions', [MeetingActionItemController::class, 'store']);
});

// Meeting Rooms
Route::get('/meeting-rooms', [MeetingRoomController::class, 'index']);
Route::post('/meeting-rooms', [MeetingRoomController::class, 'store']);
Route::put('/meeting-rooms/{id}', [MeetingRoomController::class, 'update']);
Route::delete('/meeting-rooms/{id}', [MeetingRoomController::class, 'destroy']);

Route::post('/meetings/recurring/preview', [MeetingController::class, 'previewRecurring']);
Route::get('/meetings/actions/my', [MeetingActionItemController::class, 'myActions']);
Route::patch('/meetings/actions/{id}/status', [MeetingActionItemController::class, 'updateStatus']);
Route::delete('/meetings/actions/{id}', [MeetingActionItemController::class, 'destroy']);

// Equipment & Logistics Inventory
Route::get('/meetings/materials', [MeetingLogisticsController::class, 'getMaterials']);
Route::post('/meetings/materials', [MeetingLogisticsController::class, 'storeMaterial']);
Route::put('/meetings/materials/{id}', [MeetingLogisticsController::class, 'updateMaterial']);
Route::delete('/meetings/materials/{id}', [MeetingLogisticsController::class, 'deleteMaterial']);

// Dedicated Support Staff Roster
Route::get('/meetings/staff', [MeetingLogisticsController::class, 'getStaff']);
Route::post('/meetings/staff', [MeetingLogisticsController::class, 'storeStaff']);
Route::put('/meetings/staff/{id}', [MeetingLogisticsController::class, 'updateStaff']);
Route::delete('/meetings/staff/{id}', [MeetingLogisticsController::class, 'deleteStaff']);

// Room Defect & Facility Issues
Route::get('/meetings/issues', [MeetingIssueController::class, 'index']);
Route::post('/meeting-rooms/{roomId}/issues', [MeetingIssueController::class, 'report']);
Route::post('/meetings/rooms/{roomId}/issues', [MeetingIssueController::class, 'report']);
Route::patch('/meetings/issues/{id}/status', [MeetingIssueController::class, 'updateStatus']);

// In-App Notifications
Route::get('/meetings/notifications', [MeetingNotificationController::class, 'index']);
Route::patch('/meetings/notifications/{id}/read', [MeetingNotificationController::class, 'markRead']);
Route::post('/meetings/notifications/mark-all-read', [MeetingNotificationController::class, 'markAllRead']);

// Runtime Governance & Dynamic System Settings
Route::get('/meetings/system-settings', [MeetingSettingController::class, 'index']);
Route::put('/meetings/system-settings/batch', [MeetingSettingController::class, 'batchUpdate']);
Route::post('/meetings/system-settings/reset', [MeetingSettingController::class, 'reset']);

// Telegram Bot Alerts Gateway & Dynamic Templates Engine
Route::get('/meetings/telegram/status', [MeetingTelegramController::class, 'status']);
Route::get('/meetings/telegram/config', [MeetingTelegramController::class, 'config']);
Route::put('/meetings/telegram/config', [MeetingTelegramController::class, 'updateConfig']);
Route::post('/meetings/telegram/validate-token', [MeetingTelegramController::class, 'validateToken']);
Route::post('/meetings/telegram/test', [MeetingTelegramController::class, 'test']);
Route::get('/meetings/telegram/templates', [MeetingTelegramController::class, 'templates']);
Route::put('/meetings/telegram/templates/{type}', [MeetingTelegramController::class, 'updateTemplate']);
Route::post('/meetings/telegram/templates/{type}/reset', [MeetingTelegramController::class, 'resetTemplate']);
Route::post('/meetings/telegram/templates/preview', [MeetingTelegramController::class, 'previewTemplate']);
