<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class MeetingTelegramController extends Controller
{
    /**
     * Get workspace Telegram bot settings.
     */
    public function show(Request $request)
    {
        $currentUser = $request->user();
        $tenantId = $currentUser?->tenant_id;

        $config = DB::table('meeting_telegram_configs')->where('tenant_id', $tenantId)->first();
        return response()->json(['status' => 'success', 'data' => $config]);
    }

    /**
     * Update workspace Telegram bot settings.
     */
    public function update(Request $request)
    {
        $validated = $request->validate([
            'bot_token' => 'nullable|string|max:255',
            'chat_id' => 'nullable|string|max:100',
            'is_enabled' => 'boolean',
            'notify_on_booking' => 'boolean',
            'notify_15m_before' => 'boolean',
        ]);

        $currentUser = $request->user();
        $tenantId = $currentUser?->tenant_id;

        $existing = DB::table('meeting_telegram_configs')->where('tenant_id', $tenantId)->first();

        if ($existing) {
            DB::table('meeting_telegram_configs')->where('tenant_id', $tenantId)->update(
                array_merge($validated, ['updated_at' => now()])
            );
        } else {
            DB::table('meeting_telegram_configs')->insert(array_merge($validated, [
                'tenant_id' => $tenantId,
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }

        return response()->json(['status' => 'success', 'message' => 'Telegram configuration updated.']);
    }

    /**
     * Send test alert via configured Telegram bot.
     */
    public function testNotification(Request $request)
    {
        $currentUser = $request->user();
        $tenantId = $currentUser?->tenant_id;
        $config = DB::table('meeting_telegram_configs')->where('tenant_id', $tenantId)->first();

        if (!$config || empty($config->bot_token) || empty($config->chat_id)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Telegram Bot Token and Chat ID must be configured first.',
            ], 422);
        }

        $message = "🔔 *CodeBridges MMS Telegram Test*\n\nHello! Telegram alerts are successfully linked for your workspace.";

        try {
            $response = Http::post("https://api.telegram.org/bot{$config->bot_token}/sendMessage", [
                'chat_id' => $config->chat_id,
                'text' => $message,
                'parse_mode' => 'Markdown',
            ]);

            if ($response->successful()) {
                return response()->json(['status' => 'success', 'message' => 'Test message sent to Telegram!']);
            }
            return response()->json(['status' => 'error', 'message' => 'Telegram API returned an error: ' . $response->body()], 400);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => 'Could not connect to Telegram: ' . $e->getMessage()], 500);
        }
    }
}
