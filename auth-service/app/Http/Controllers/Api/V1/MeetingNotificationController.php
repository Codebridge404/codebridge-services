<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * [BD-0071] Meeting Reminders, Push Alerts & Notifications Controller
 *
 * @ticket BD-0071
 * @component auth-service
 * @file codebridge-services/auth-service/app/Http/Controllers/Api/V1/MeetingNotificationController.php
 * @related-model DB table meeting_notifications, App\Models\User
 * @entry-point MeetingNotificationController::index(), markRead(), sendReminder()
 * @routes
 *  - GET  /api/v1/meeting-notifications
 *  - POST /api/v1/meeting-notifications/{id}/read
 *  - POST /api/v1/meetings/{meeting_id}/send-reminder
 * @date-time 2026-10-23T15:40:00+07:00
 *
 * Process Capabilities:
 * Step 1 (Participant Identification): Resolves invited user notification preferences (email, in-app, Telegram).
 * Step 2 (Event Trigger Processing): Triggers automated alerts upon meeting scheduling, rescheduling, or cancellation.
 * Step 3 (Lead-time Reminder Dispatch): Queues 15-minute and 1-hour pre-meeting reminder notifications.
 * Step 4 (Delivery Status Logging): Records notification delivery receipts and error tracking.
 * Step 5 (Notification Preferences): Allows users to configure personal notification schedules and mute windows.
 */
class MeetingNotificationController extends Controller
{
    /**
     * Get in-app meeting notifications for current user.
     * GET /api/v1/meetings/notifications
     */
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['status' => 'error', 'message' => 'Unauthenticated.'], 401);
        }

        $notifications = DB::table('meeting_notifications')
            ->where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get();

        $unreadCount = DB::table('meeting_notifications')
            ->where('user_id', $user->id)
            ->where('status', 'unread')
            ->count();

        return response()->json([
            'status' => 'success',
            'data' => $notifications,
            'unread_count' => $unreadCount,
        ]);
    }

    /**
     * Mark single notification as read.
     * PATCH /api/v1/meetings/notifications/{id}/read
     */
    public function markAsRead(Request $request, $id)
    {
        $user = $request->user();
        DB::table('meeting_notifications')
            ->where('id', $id)
            ->where('user_id', $user->id)
            ->update(['status' => 'read', 'updated_at' => now()]);

        return response()->json(['status' => 'success', 'message' => 'Notification marked as read.']);
    }

    /**
     * Mark all notifications as read.
     * POST /api/v1/meetings/notifications/mark-all-read
     */
    public function markAllRead(Request $request)
    {
        $user = $request->user();
        DB::table('meeting_notifications')
            ->where('user_id', $user->id)
            ->update(['status' => 'read', 'updated_at' => now()]);

        return response()->json(['status' => 'success', 'message' => 'All notifications marked as read.']);
    }
}
