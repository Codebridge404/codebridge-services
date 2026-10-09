<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MeetingMinutesController extends Controller
{
    /**
     * Get official minutes & action items for a meeting.
     */
    public function show($meetingId)
    {
        $minutes = DB::table('meeting_minutes as mm')
            ->leftJoin('users as u', 'mm.recorded_by', '=', 'u.id')
            ->where('mm.meeting_id', $meetingId)
            ->select('mm.*', 'u.name as recorder_name')
            ->first();

        if ($minutes && !empty($minutes->action_items)) {
            $minutes->action_items = json_decode($minutes->action_items, true);
        }

        return response()->json(['status' => 'success', 'data' => $minutes]);
    }

    /**
     * Save or publish minutes and action items.
     */
    public function storeOrUpdate(Request $request, $meetingId)
    {
        $validated = $request->validate([
            'content' => 'required|string',
            'action_items' => 'nullable|array',
            'status' => 'nullable|string',
        ]);

        $currentUser = $request->user();
        $actionItemsJson = !empty($validated['action_items']) ? json_encode($validated['action_items']) : null;

        $existing = DB::table('meeting_minutes')->where('meeting_id', $meetingId)->first();

        if ($existing) {
            DB::table('meeting_minutes')->where('meeting_id', $meetingId)->update([
                'content' => $validated['content'],
                'action_items' => $actionItemsJson,
                'status' => $validated['status'] ?? $existing->status,
                'updated_at' => now(),
            ]);
        } else {
            DB::table('meeting_minutes')->insert([
                'meeting_id' => $meetingId,
                'recorded_by' => $currentUser->id,
                'content' => $validated['content'],
                'action_items' => $actionItemsJson,
                'status' => $validated['status'] ?? 'draft',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return response()->json(['status' => 'success', 'message' => 'Meeting minutes saved successfully.']);
    }
}
