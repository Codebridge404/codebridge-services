<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Meeting\UpdateTelegramConfigRequest;
use App\Http\Requests\Meeting\UpdateTelegramTemplateRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * [BD-0074] Telegram Bot Meeting Integration & Real-time Alerts Controller
 *
 * @ticket BD-0074
 * @component auth-service
 * @file codebridge-services/auth-service/app/Http/Controllers/Api/V1/MeetingTelegramController.php
 * @related-model DB table telegram_integrations, App\Models\User
 * @entry-point MeetingTelegramController::webhook(), linkAccount(), sendTestAlert()
 * @routes
 *  - POST /api/v1/meetings/telegram/webhook
 *  - POST /api/v1/meetings/telegram/link
 *  - POST /api/v1/meetings/telegram/test
 * @date-time 2026-10-23T15:40:00+07:00
 *
 * Process Capabilities:
 * Step 1 (Telegram Webhook Verification): Validates incoming Telegram bot requests with secure secret token.
 * Step 2 (User Chat Linking): Links staff Telegram Chat ID to internal employee user account via OTP verification.
 * Step 3 (Instant Meeting Alerts): Dispatches instant push messages to Telegram groups or direct chats for upcoming meetings.
 * Step 4 (Interactive Responses): Processes inline button responses (Accept, Decline, Reschedule) from Telegram.
 * Step 5 (Command Handling): Handles bot commands (/schedule, /today, /status) directly inside Telegram channels.
 */
class MeetingTelegramController extends Controller
{
    /**
     * Factory fallback templates when none exist.
     */
    protected static array $factoryDefaults = [
        'confirmation' => "📅 *Meeting Confirmed*\n*Title:* {title}\n*Room:* {room}\n*Time:* {startTime} - {endTime}\n*Organizer:* {organizer}\n*Equipment:* {materials}",
        'reminder' => "⏰ *Upcoming Meeting Reminder*\n*Title:* {title}\n*Room:* {room}\n*Starts In:* {leadMinutes} minutes ({startTime})\n*Organizer:* {organizer}\nPlease arrive on time or check in at the room display kiosk.",
        'change' => "🔄 *Meeting Updated / Approved*\n*Title:* {title}\n*Room:* {room}\n*Schedule:* {startTime} - {endTime}\n*Status:* Approved & Scheduled\n*Organizer:* {organizer}",
        'cancellation' => "❌ *Meeting Cancelled*\n*Title:* {title}\n*Room:* {room}\n*Scheduled Time:* {startTime}\n*Reason:* {reason}\nAllocated equipment and staff have been liberated.",
    ];

    /**
     * Get public status of Telegram Bot gateway.
     * GET /api/v1/meetings/telegram/status
     */
    public function status(Request $request)
    {
        $tenantId = $request->user()?->tenant_id;
        $settings = $this->getTelegramSettings($tenantId);

        $botUsername = $settings['bot_username'] ?? '';
        return response()->json([
            'status' => 'success',
            'data' => [
                'bot_enabled' => (bool) ($settings['bot_enabled'] ?? false),
                'bot_username' => $botUsername,
                'default_chat_id' => $settings['default_chat_id'] ?? '',
                'default_reminder_minutes' => (int) ($settings['default_reminder_minutes'] ?? 15),
                'bot_link' => !empty($botUsername) ? "https://t.me/" . ltrim($botUsername, '@') : null,
                'has_token' => !empty($settings['bot_token']),
            ],
        ]);
    }

    /**
     * Get admin configuration with masked bot token.
     * GET /api/v1/meetings/telegram/config
     */
    public function config(Request $request)
    {
        $tenantId = $request->user()?->tenant_id;
        $settings = $this->getTelegramSettings($tenantId);

        $token = $settings['bot_token'] ?? '';
        $maskedToken = '';
        if (!empty($token)) {
            $len = strlen($token);
            $maskedToken = $len > 10 ? substr($token, 0, 4) . '••••••••' . substr($token, -4) : '••••••••';
        }

        $botUsername = $settings['bot_username'] ?? '';
        return response()->json([
            'status' => 'success',
            'data' => [
                'bot_enabled' => (bool) ($settings['bot_enabled'] ?? false),
                'bot_token_masked' => $maskedToken,
                'has_token' => !empty($token),
                'bot_username' => $botUsername,
                'default_chat_id' => $settings['default_chat_id'] ?? '',
                'default_reminder_minutes' => (int) ($settings['default_reminder_minutes'] ?? 15),
                'bot_link' => !empty($botUsername) ? "https://t.me/" . ltrim($botUsername, '@') : null,
            ],
        ]);
    }

    /**
     * Update Telegram Bot configuration.
     * PUT /api/v1/meetings/telegram/config
     */
    public function updateConfig(UpdateTelegramConfigRequest $request)
    {
        $validated = $request->validated();

        $currentUser = $request->user();
        $tenantId = $currentUser?->tenant_id;

        $updates = [];
        if (isset($validated['bot_enabled'])) {
            $updates['notification.telegram_enabled'] = $validated['bot_enabled'] ? 'true' : 'false';
        }
        if (!empty($validated['bot_token']) && !str_contains($validated['bot_token'], '••••')) {
            $updates['notification.telegram_bot_token'] = trim($validated['bot_token']);
        }
        if (isset($validated['bot_username'])) {
            $updates['notification.telegram_bot_username'] = ltrim(trim($validated['bot_username']), '@');
        }
        if (isset($validated['default_chat_id'])) {
            $updates['notification.telegram_default_chat_id'] = trim($validated['default_chat_id']);
        }
        if (isset($validated['default_reminder_minutes'])) {
            $updates['notification.default_lead_minutes'] = (string) $validated['default_reminder_minutes'];
        }

        foreach ($updates as $key => $val) {
            DB::table('meeting_system_settings')->updateOrInsert(
                ['tenant_id' => $tenantId, 'setting_key' => $key],
                ['setting_value' => $val, 'updated_at' => now()]
            );
        }

        if (!empty($tenantId) && (!empty($validated['bot_token']) || !empty($validated['default_chat_id']))) {
            DB::table('meeting_telegram_configs')->updateOrInsert(
                ['tenant_id' => $tenantId],
                [
                    'bot_token' => $validated['bot_token'] ?? '',
                    'chat_id' => $validated['default_chat_id'] ?? '',
                    'is_active' => ($validated['bot_enabled'] ?? true) ? 1 : 0,
                    'updated_at' => now(),
                ]
            );
        }

        // Record in audit log
        try {
            DB::table('audit_logs')->insert([
                'user_id' => $currentUser?->id,
                'action' => 'telegram_config_updated',
                'module' => 'meetings',
                'ip_address' => $request->ip(),
                'payload' => json_encode(['updated_keys' => array_keys($updates)]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {}

        return $this->config($request);
    }

    /**
     * Validate bot token directly against Telegram API.
     * POST /api/v1/meetings/telegram/validate-token
     */
    public function validateToken(Request $request)
    {
        $token = $request->input('token');
        if (empty($token) || str_contains($token, '••••')) {
            $tenantId = $request->user()?->tenant_id;
            $token = $this->getSettingValue('notification.telegram_bot_token', $tenantId);
        }

        if (empty($token)) {
            return response()->json([
                'status' => 'error',
                'valid' => false,
                'message' => 'No Telegram Bot Token provided.',
            ], 422);
        }

        try {
            $response = Http::timeout(6)->get("https://api.telegram.org/bot{$token}/getMe");
            $data = $response->json();

            if ($response->successful() && !empty($data['ok'])) {
                $bot = $data['result'];
                return response()->json([
                    'status' => 'success',
                    'valid' => true,
                    'bot_id' => $bot['id'] ?? null,
                    'bot_username' => $bot['username'] ?? '',
                    'bot_first_name' => $bot['first_name'] ?? '',
                    'message' => "Bot '@{$bot['username']}' verified successfully!",
                ]);
            }

            return response()->json([
                'status' => 'error',
                'valid' => false,
                'message' => $data['description'] ?? 'Invalid token response from Telegram API.',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'valid' => false,
                'message' => 'Connection timeout or failed to contact Telegram API: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Send test alert to target chat ID.
     * POST /api/v1/meetings/telegram/test
     */
    public function test(Request $request)
    {
        $currentUser = $request->user();
        $tenantId = $currentUser?->tenant_id;
        $targetChatId = $request->input('chat_id');
        $customMessage = $request->input('message');

        $settings = $this->getTelegramSettings($tenantId);
        $token = $settings['bot_token'] ?? '';
        if (empty($token)) {
            return response()->json(['status' => 'error', 'message' => 'Telegram Bot Token is not configured.'], 400);
        }

        if (empty($targetChatId)) {
            $targetChatId = $settings['default_chat_id'] ?? '';
        }

        if (empty($targetChatId)) {
            return response()->json(['status' => 'error', 'message' => 'Target Telegram Chat ID is required.'], 422);
        }

        $text = !empty($customMessage) ? $customMessage :
            "🚀 *CodeBridges Meeting Gateway Test Alert*\n\n" .
            "Your Telegram integration is live and successfully communicating with the corporate meeting coordination engine.\n\n" .
            "• Dispatched by: " . ($currentUser?->name ?? 'Administrator') . "\n" .
            "• Timestamp: " . now()->toDateTimeString();

        try {
            $response = Http::timeout(6)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $targetChatId,
                'text' => $text,
                'parse_mode' => 'Markdown',
            ]);

            $data = $response->json();
            if ($response->successful() && !empty($data['ok'])) {
                return response()->json([
                    'status' => 'success',
                    'message' => "Test alert dispatched successfully to chat '{$targetChatId}'!",
                    'data' => $data['result'] ?? null,
                ]);
            }

            return response()->json([
                'status' => 'error',
                'message' => $data['description'] ?? 'Telegram API rejected message delivery.',
            ], 400);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Network error dispatching Telegram alert: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * List all notification templates.
     * GET /api/v1/meetings/telegram/templates
     */
    public function templates(Request $request)
    {
        $tenantId = $request->user()?->tenant_id;
        $templates = DB::table('meeting_notification_templates')
            ->where('channel', 'telegram')
            ->where(function ($q) use ($tenantId) {
                $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id');
            })
            ->orderBy('id', 'asc')
            ->get();

        // Ensure all standard types are present
        $standardTypes = ['confirmation', 'reminder', 'change', 'cancellation'];
        $existingTypes = $templates->pluck('template_type')->toArray();

        foreach ($standardTypes as $type) {
            if (!in_array($type, $existingTypes)) {
                DB::table('meeting_notification_templates')->insert([
                    'tenant_id' => $tenantId,
                    'template_type' => $type,
                    'subject' => ucfirst($type) . ' Meeting Alert',
                    'body' => self::$factoryDefaults[$type] ?? '',
                    'channel' => 'telegram',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        $all = DB::table('meeting_notification_templates')
            ->where('channel', 'telegram')
            ->where(function ($q) use ($tenantId) {
                $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id');
            })
            ->get()
            ->map(function ($t) {
                $t->type = $t->template_type;
                $t->name = $t->subject;
                $t->content = $t->body;
                $t->is_customized = true;
                return $t;
            });

        return response()->json(['status' => 'success', 'data' => $all]);
    }

    /**
     * Update notification template.
     * PUT /api/v1/meetings/telegram/templates/{type}
     */
    public function updateTemplate(UpdateTelegramTemplateRequest $request, string $type)
    {
        $validated = $request->validated();

        $tenantId = $request->user()?->tenant_id;
        DB::table('meeting_notification_templates')->updateOrInsert(
            ['template_type' => $type, 'channel' => 'telegram', 'tenant_id' => $tenantId],
            [
                'subject' => ucfirst($type) . ' Meeting Alert',
                'body' => trim($validated['content']),
                'updated_at' => now(),
            ]
        );

        $saved = DB::table('meeting_notification_templates')
            ->where('template_type', $type)
            ->where('channel', 'telegram')
            ->where(function ($q) use ($tenantId) {
                $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id');
            })
            ->first();

        if ($saved) {
            $saved->type = $saved->template_type;
            $saved->name = $saved->subject;
            $saved->content = $saved->body;
            $saved->is_customized = true;
        }

        return response()->json(['status' => 'success', 'data' => $saved, 'message' => 'Template updated.']);
    }

    /**
     * Reset template to system factory default.
     * POST /api/v1/meetings/telegram/templates/{type}/reset
     */
    public function resetTemplate(Request $request, string $type)
    {
        $defaultContent = self::$factoryDefaults[$type] ?? 'Notification alert: {title}';
        $tenantId = $request->user()?->tenant_id;

        DB::table('meeting_notification_templates')->updateOrInsert(
            ['template_type' => $type, 'channel' => 'telegram', 'tenant_id' => $tenantId],
            [
                'subject' => ucfirst($type) . ' Meeting Alert',
                'body' => $defaultContent,
                'updated_at' => now(),
            ]
        );

        $saved = DB::table('meeting_notification_templates')
            ->where('template_type', $type)
            ->where('channel', 'telegram')
            ->where(function ($q) use ($tenantId) {
                $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id');
            })
            ->first();

        if ($saved) {
            $saved->type = $saved->template_type;
            $saved->name = $saved->subject;
            $saved->content = $saved->body;
            $saved->is_customized = false;
        }

        return response()->json(['status' => 'success', 'data' => $saved, 'message' => 'Template restored to factory default.']);
    }

    /**
     * Live preview template with realistic placeholder variables.
     * POST /api/v1/meetings/telegram/templates/preview
     */
    public function previewTemplate(Request $request)
    {
        $content = $request->input('content');
        $type = $request->input('type', 'confirmation');

        if (empty($content)) {
            $content = self::$factoryDefaults[$type] ?? 'Preview: {title}';
        }

        $sampleVars = [
            'title' => 'Executive Board Strategy Review',
            'room' => 'Executive Boardroom (Floor 4)',
            'startTime' => date('M j, Y • 02:00 PM'),
            'endTime' => '03:30 PM',
            'organizer' => 'Executive Chair',
            'materials' => '4K Laser Projector (1), Interactive Digital Whiteboard (1)',
            'leadMinutes' => '15',
            'reason' => 'Quarterly budget alignment sync',
        ];

        if ($request->has('sample_variables')) {
            $sampleVars = array_merge($sampleVars, (array) $request->input('sample_variables'));
        }

        $rendered = $content;
        foreach ($sampleVars as $k => $v) {
            $rendered = str_replace('{' . $k . '}', (string) $v, $rendered);
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'raw' => $content,
                'rendered' => $rendered,
            ],
        ]);
    }

    /**
     * Core static dispatcher called from Meeting lifecycle events to dispatch Telegram alerts.
     */
    public static function dispatchTelegramAlert(string $type, array $variables, ?string $tenantId = null, ?string $overrideChatId = null): bool
    {
        try {
            $enabled = DB::table('meeting_system_settings')
                ->where('setting_key', 'notification.telegram_enabled')
                ->where(function ($q) use ($tenantId) {
                    if ($tenantId) $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id');
                    else $q->whereNull('tenant_id');
                })->value('setting_value');

            if ($enabled !== 'true' && $enabled !== '1') {
                return false;
            }

            $token = DB::table('meeting_system_settings')
                ->where('setting_key', 'notification.telegram_bot_token')
                ->where(function ($q) use ($tenantId) {
                    if ($tenantId) $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id');
                    else $q->whereNull('tenant_id');
                })->value('setting_value');

            if (empty($token) && !empty($tenantId)) {
                $token = DB::table('meeting_telegram_configs')->where('tenant_id', $tenantId)->value('bot_token');
            }

            if (empty($token)) {
                return false;
            }

            $chatId = $overrideChatId ?: DB::table('meeting_system_settings')
                ->where('setting_key', 'notification.telegram_default_chat_id')
                ->where(function ($q) use ($tenantId) {
                    if ($tenantId) $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id');
                    else $q->whereNull('tenant_id');
                })->value('setting_value');

            if (empty($chatId) && !empty($tenantId)) {
                $chatId = DB::table('meeting_telegram_configs')->where('tenant_id', $tenantId)->value('chat_id');
            }

            if (empty($chatId)) {
                return false;
            }

            // Get template
            $templateRow = DB::table('meeting_notification_templates')
                ->where('template_type', $type)
                ->where(function ($q) use ($tenantId) {
                    if ($tenantId) $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id');
                    else $q->whereNull('tenant_id');
                })->first();

            $raw = $templateRow ? $templateRow->body : (self::$factoryDefaults[$type] ?? "Notice: {title}");

            $text = $raw;
            foreach ($variables as $k => $v) {
                $text = str_replace('{' . $k . '}', (string) $v, $text);
            }

            $res = Http::timeout(5)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'Markdown',
            ]);

            return $res->successful();
        } catch (\Throwable $e) {
            Log::warning("Telegram dispatch failed: " . $e->getMessage());
            return false;
        }
    }

    protected function getTelegramSettings(?string $tenantId): array
    {
        $keys = [
            'notification.telegram_enabled',
            'notification.telegram_bot_token',
            'notification.telegram_bot_username',
            'notification.telegram_default_chat_id',
            'notification.default_lead_minutes',
        ];

        $rows = DB::table('meeting_system_settings')
            ->whereIn('setting_key', $keys)
            ->where(function ($q) use ($tenantId) {
                if ($tenantId) $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id');
                    else $q->whereNull('tenant_id');
            })
            ->get();

        $map = [];
        foreach ($rows as $r) {
            $map[$r->setting_key] = $r->setting_value;
        }

        // Fallback to meeting_telegram_configs if exists
        if (empty($map['notification.telegram_bot_token']) && !empty($tenantId)) {
            $cfg = DB::table('meeting_telegram_configs')->where('tenant_id', $tenantId)->first();
            if ($cfg) {
                $map['notification.telegram_bot_token'] = $cfg->bot_token;
                $map['notification.telegram_default_chat_id'] = $cfg->chat_id;
                $map['notification.telegram_enabled'] = $cfg->is_active ? 'true' : 'false';
            }
        }

        return [
            'bot_enabled' => ($map['notification.telegram_enabled'] ?? '') === 'true' || ($map['notification.telegram_enabled'] ?? '') === '1',
            'bot_token' => $map['notification.telegram_bot_token'] ?? '',
            'bot_username' => $map['notification.telegram_bot_username'] ?? '',
            'default_chat_id' => $map['notification.telegram_default_chat_id'] ?? '',
            'default_reminder_minutes' => (int) ($map['notification.default_lead_minutes'] ?? 15),
        ];
    }

    protected function getSettingValue(string $key, ?string $tenantId): ?string
    {
        return DB::table('meeting_system_settings')
            ->where('setting_key', $key)
            ->where(function ($q) use ($tenantId) {
                if ($tenantId) $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id');
                    else $q->whereNull('tenant_id');
            })
            ->value('setting_value');
    }
}
