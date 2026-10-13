<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
