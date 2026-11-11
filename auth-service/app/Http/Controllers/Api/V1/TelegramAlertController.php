<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\TelegramAlert\TelegramAlertResource;
use App\Models\NotificationProvider;
use App\Services\UserAuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * [BD-0066] Telegram Real-Time Alerts & Notification Management Controller
 *
 * Provides dedicated API endpoints to inspect bot health, view alert history,
 * send live test alerts, and manage message templates.
 */
class TelegramAlertController extends Controller
{
    protected static array $factoryDefaults = [
        'confirmation' => "📅 *Meeting Confirmed*\n*Title:* {title}\n*Room:* {room}\n*Time:* {startTime} - {endTime}\n*Organizer:* {organizer}\n*Equipment:* {materials}",
        'reminder' => "⏰ *Upcoming Meeting Reminder*\n*Title:* {title}\n*Room:* {room}\n*Starts In:* {leadMinutes} minutes ({startTime})\n*Organizer:* {organizer}\nPlease arrive on time or check in at the room display kiosk.",
        'change' => "🔄 *Meeting Updated / Approved*\n*Title:* {title}\n*Room:* {room}\n*Schedule:* {startTime} - {endTime}\n*Status:* Approved & Scheduled\n*Organizer:* {organizer}",
        'cancellation' => "❌ *Meeting Cancelled*\n*Title:* {title}\n*Room:* {room}\n*Scheduled Time:* {startTime}\n*Reason:* {reason}\nAllocated equipment and staff have been liberated.",
    ];

    /**
     * Get public / admin health status of Telegram Bot gateway.
     * GET /api/v1/telegram-alerts/status
     */
    public function status(Request $request): JsonResponse
    {
        $tenantId = $request->user()?->tenant_id;

        $provider = NotificationProvider::where('channel', 'telegram')
            ->where(function ($q) use ($tenantId) {
                if ($tenantId !== null) {
                    $q->where('tenant_id', (int) $tenantId);
                } else {
                    $q->whereNull('tenant_id');
                }
            })
            ->latest('id')
            ->first();

        $hasToken = false;
        $maskedToken = '';
        $botUsername = '';
        $defaultChatId = '';
        $isEnabled = false;

        if ($provider) {
            $isEnabled = (bool) $provider->is_enabled;
            try {
                $creds = $provider->getDecryptedCredentials();
                $token = $creds['bot_token'] ?? ($creds['token'] ?? '');
                if (!empty($token)) {
                    $hasToken = true;
                    $len = strlen($token);
                    $maskedToken = $len > 10 ? substr($token, 0, 4) . '••••••••' . substr($token, -4) : '••••••••';
                }
                $defaultChatId = $creds['chat_id'] ?? '';
            } catch (\Throwable) {}

            $settings = is_array($provider->settings) ? $provider->settings : [];
            $botUsername = $settings['bot_username'] ?? '';
            $defaultChatId = $defaultChatId ?: ($settings['default_chat_id'] ?? '');
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'bot_enabled' => $isEnabled,
                'has_token' => $hasToken,
                'bot_token_masked' => $maskedToken,
                'bot_username' => $botUsername,
                'default_chat_id' => $defaultChatId,
                'bot_link' => !empty($botUsername) ? 'https://t.me/' . ltrim($botUsername, '@') : null,
            ],
            'message' => 'Telegram alert gateway status retrieved successfully.',
        ]);
    }

    /**
     * List historical dispatched alert notifications.
     * GET /api/v1/telegram-alerts/history
     */
    public function history(Request $request): JsonResponse
    {
        $user = $request->user();
        $isSuperAdmin = UserAuthorizationService::hasPlatformSuperAdminRole($user);

        $query = DB::table('meeting_notifications as mn')
            ->leftJoin('users as u', 'mn.user_id', '=', 'u.id')
            ->select('mn.*', 'u.name as user_name');

        if (! $isSuperAdmin) {
            $tenantId = $user?->tenant_id;
            if ($tenantId) {
                $query->where('mn.tenant_id', $tenantId);
            } else {
                $query->where('mn.user_id', $user?->id);
            }
        } elseif ($request->filled('tenant_id')) {
            $query->where('mn.tenant_id', $request->integer('tenant_id'));
        }

        if ($request->filled('type')) {
            $query->where('mn.type', $request->input('type'));
        }

        if ($request->filled('status')) {
            $query->where('mn.status', $request->input('status'));
        }

        $perPage = min(max($request->integer('per_page', 25), 1), 100);
        $paginator = $query->orderBy('mn.created_at', 'desc')
            ->orderBy('mn.id', 'desc')
            ->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data' => TelegramAlertResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'message' => 'Telegram alert history retrieved successfully.',
        ]);
    }

    /**
     * Send a real-time test alert through Telegram Bot API using decrypted credentials.
     * POST /api/v1/telegram-alerts/send-test
     */
    public function sendTest(Request $request): JsonResponse
    {
        $request->validate([
            'chat_id' => 'nullable|string',
            'message' => 'nullable|string|max:500',
        ]);

        $currentUser = $request->user();
        $tenantId = $currentUser?->tenant_id;

        $targetChatId = $request->input('chat_id');
        $customMessage = $request->input('message') ?: '🔔 *CodeBridge Real-Time Alert Test*' . "\n" . 'Your Telegram alert gateway is operating normally.' . "\n" . 'Timestamp: ' . now()->toDateTimeString();

        $provider = NotificationProvider::where('channel', 'telegram')
            ->where(function ($q) use ($tenantId) {
                if ($tenantId !== null) {
                    $q->where('tenant_id', (int) $tenantId);
                } else {
                    $q->whereNull('tenant_id');
                }
            })
            ->latest('id')
            ->first();

        if (! $provider || ! $provider->is_enabled) {
            return response()->json([
                'status' => 'error',
                'code' => 'BOT_NOT_CONFIGURED',
                'message' => 'Telegram bot is not configured or disabled for this organization.',
            ], 422);
        }

        try {
            $creds = $provider->getDecryptedCredentials();
            $token = $creds['bot_token'] ?? ($creds['token'] ?? null);
            $chatId = $targetChatId ?: ($creds['chat_id'] ?? ($provider->settings['default_chat_id'] ?? null));

            if (empty($token) || empty($chatId)) {
                return response()->json([
                    'status' => 'error',
                    'code' => 'MISSING_CREDENTIALS',
                    'message' => 'Telegram bot token or target chat ID is missing.',
                ], 422);
            }

            $response = Http::timeout(5)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $customMessage,
                'parse_mode' => 'Markdown',
            ]);

            if ($response->successful()) {
                // Record in audit log
                $this->recordAuditLog(
                    action: 'telegram_test_alert_sent',
                    module: 'meetings',
                    entityType: NotificationProvider::class,
                    entityId: (string) $provider->id,
                    details: "Test alert dispatched to Telegram chat {$chatId}"
                );

                return response()->json([
                    'status' => 'success',
                    'data' => [
                        'delivered' => true,
                        'chat_id' => $chatId,
                        'telegram_message_id' => $response->json('result.message_id'),
                    ],
                    'message' => 'Telegram test alert delivered successfully.',
                ]);
            }

            $sanitizedDesc = preg_replace('/\/bot[^\/\s]+/', '/bot[REDACTED]', (string) $response->json('description')) ?? 'Telegram API rejected message';
            $sanitizedDesc = str_replace((string) $token, '[REDACTED]', $sanitizedDesc);

            return response()->json([
                'status' => 'error',
                'code' => 'DELIVERY_FAILED',
                'message' => "Telegram delivery failed: {$sanitizedDesc}",
            ], 400);
        } catch (\Throwable $e) {
            $sanitizedError = preg_replace('/\/bot[^\/\s]+/', '/bot[REDACTED]', $e->getMessage()) ?? 'Unknown error';
            $sanitizedError = isset($token) && is_string($token) && $token !== ''
                ? str_replace($token, '[REDACTED]', $sanitizedError)
                : $sanitizedError;
            Log::warning('Telegram sendTest failed: ' . $sanitizedError);

            return response()->json([
                'status' => 'error',
                'code' => 'NETWORK_ERROR',
                'message' => "Telegram connection failed: {$sanitizedError}",
            ], 500);
        }
    }

    /**
     * Preview alert message with dynamic variables.
     * POST /api/v1/telegram-alerts/preview
     */
    public function preview(Request $request): JsonResponse
    {
        $content = $request->input('content');
        $type = $request->input('type', 'confirmation');

        if (empty($content)) {
            $content = self::$factoryDefaults[$type] ?? 'Notice: {title}';
        }

        $sampleVars = [
            'title' => 'Quarterly Strategic Planning',
            'room' => 'Boardroom Alpha',
            'startTime' => date('M j, Y • 10:00 AM'),
            'endTime' => '11:30 AM',
            'organizer' => 'Chief Executive',
            'materials' => 'Smart Whiteboard, HD Video Kiosk',
            'leadMinutes' => '15',
            'reason' => 'Annual performance review',
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
            'message' => 'Template preview generated successfully.',
        ]);
    }

    /**
     * List notification templates.
     * GET /api/v1/telegram-alerts/templates
     */
    public function templates(Request $request): JsonResponse
    {
        $tenantId = $request->user()?->tenant_id;

        $dbTemplates = DB::table('meeting_notification_templates')
            ->where(function ($q) use ($tenantId) {
                if ($tenantId) $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id');
                else $q->whereNull('tenant_id');
            })
            ->get()
            ->keyBy('template_type');

        $result = [];
        foreach (self::$factoryDefaults as $type => $defaultBody) {
            $row = $dbTemplates->get($type);
            $result[$type] = [
                'type' => $type,
                'body' => $row ? $row->body : $defaultBody,
                'is_customized' => $row && !empty($row->tenant_id),
                'default' => $defaultBody,
            ];
        }

        return response()->json([
            'status' => 'success',
            'data' => $result,
            'message' => 'Templates retrieved successfully.',
        ]);
    }

    /**
     * Update notification template.
     * PUT /api/v1/telegram-alerts/templates/{type}
     */
    public function updateTemplate(Request $request, string $type): JsonResponse
    {
        $request->validate(['body' => 'required|string|min:5|max:2000']);

        if (! array_key_exists($type, self::$factoryDefaults)) {
            return response()->json([
                'status' => 'error',
                'code' => 'INVALID_TYPE',
                'message' => "Template type '{$type}' is not supported.",
            ], 422);
        }

        $tenantId = $request->user()?->tenant_id;
        $subject = $request->input('subject') ?: ucfirst($type) . ' Notification';

        DB::table('meeting_notification_templates')->updateOrInsert(
            ['tenant_id' => $tenantId, 'template_type' => $type],
            [
                'subject' => $subject,
                'body' => $request->input('body'),
                'channel' => 'telegram',
                'updated_at' => now(),
            ]
        );

        return response()->json([
            'status' => 'success',
            'message' => "Template '{$type}' updated successfully.",
        ]);
    }

    /**
     * Reset notification template to factory default.
     * POST /api/v1/telegram-alerts/templates/{type}/reset
     */
    public function resetTemplate(Request $request, string $type): JsonResponse
    {
        if (! array_key_exists($type, self::$factoryDefaults)) {
            return response()->json([
                'status' => 'error',
                'code' => 'INVALID_TYPE',
                'message' => "Template type '{$type}' is not supported.",
            ], 422);
        }

        $tenantId = $request->user()?->tenant_id;

        DB::table('meeting_notification_templates')
            ->where('tenant_id', $tenantId)
            ->where('template_type', $type)
            ->delete();

        return response()->json([
            'status' => 'success',
            'data' => [
                'type' => $type,
                'body' => self::$factoryDefaults[$type],
            ],
            'message' => "Template '{$type}' reset to factory default.",
        ]);
    }
}
