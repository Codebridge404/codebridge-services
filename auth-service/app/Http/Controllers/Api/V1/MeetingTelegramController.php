<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\CredentialEncryptionException;
use App\Exceptions\TelegramPermanentException;
use App\Exceptions\TelegramRetryableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Meeting\UpdateTelegramConfigRequest;
use App\Http\Requests\Meeting\UpdateTelegramTemplateRequest;
use App\Jobs\SendMeetingTelegramAlertJob;
use App\Models\NotificationProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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

        // [P1 Fix] Move bot credentials into encrypted NotificationProvider store
        $botToken = !empty($validated['bot_token']) && !str_contains($validated['bot_token'], '••••')
            ? trim($validated['bot_token'])
            : null;

        $provider = NotificationProvider::where('channel', 'telegram')
            ->where(function ($q) use ($tenantId) {
                if ($tenantId !== null) $q->where('tenant_id', $tenantId);
                else $q->whereNull('tenant_id');
            })
            ->latest('id')
            ->first();

        if ($botToken !== null || $provider !== null) {
            if (!$provider) {
                $provider = new NotificationProvider();
                $provider->uuid = (string) Str::uuid();
                $provider->tenant_id = $tenantId;
                $provider->channel = 'telegram';
                $provider->provider = 'telegram';
                $provider->name = 'Telegram Meeting Alerts';
                $provider->environment = 'all';
                $provider->created_by = $currentUser?->id;
            }

            $provider->updated_by = $currentUser?->id;
            if (isset($validated['bot_enabled'])) {
                $provider->is_enabled = (bool) $validated['bot_enabled'];
            }

            $currentCreds = [];
            try {
                if ($provider->exists && !empty($provider->credentials)) {
                    $currentCreds = $provider->getDecryptedCredentials();
                }
            } catch (\Throwable) {}

            $tokenToStore = $botToken ?? ($currentCreds['bot_token'] ?? ($currentCreds['token'] ?? null));
            $chatIdToStore = !empty($validated['default_chat_id'])
                ? trim($validated['default_chat_id'])
                : ($currentCreds['chat_id'] ?? ($currentCreds['default_chat_id'] ?? ''));

            if (!empty($tokenToStore)) {
                $provider->credentials = [
                    'bot_token' => $tokenToStore,
                    'chat_id' => $chatIdToStore,
                ];
            }

            $currentSettings = is_array($provider->settings) ? $provider->settings : [];
            if (isset($validated['bot_username'])) {
                $currentSettings['bot_username'] = ltrim(trim($validated['bot_username']), '@');
            }
            if (isset($validated['default_chat_id'])) {
                $currentSettings['default_chat_id'] = trim($validated['default_chat_id']);
            }
            if (isset($validated['default_reminder_minutes'])) {
                $currentSettings['default_reminder_minutes'] = (int) $validated['default_reminder_minutes'];
            }
            $provider->settings = $currentSettings;

            if (!empty($tokenToStore)) {
                $provider->save();
            }
        }

        // [P1 Fix] Ensure NO raw token remains in either legacy table
        DB::table('meeting_system_settings')
            ->where(function ($q) use ($tenantId) {
                if ($tenantId !== null) $q->where('tenant_id', $tenantId);
                else $q->whereNull('tenant_id');
            })
            ->where('setting_key', 'notification.telegram_bot_token')
            ->delete();

        if (!empty($tenantId)) {
            DB::table('meeting_telegram_configs')
                ->where('tenant_id', $tenantId)
                ->update([
                    'bot_token' => '', // Plaintext token strictly wiped
                    'chat_id' => $validated['default_chat_id'] ?? '',
                    'is_active' => ($validated['bot_enabled'] ?? true) ? 1 : 0,
                    'updated_at' => now(),
                ]);
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
            $settings = $this->getTelegramSettings($tenantId ? (string) $tenantId : null);
            $token = $settings['bot_token'] ?? '';
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
                'message' => self::sanitizeTelegramSecrets((string) ($data['description'] ?? 'Invalid token response from Telegram API.'), $token),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'valid' => false,
                'message' => 'Connection timeout or failed to contact Telegram API: ' . self::sanitizeTelegramSecrets($e->getMessage(), $token),
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

        $settings = $this->getTelegramSettings($tenantId ? (string) $tenantId : null);
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
                'message' => self::sanitizeTelegramSecrets((string) ($data['description'] ?? 'Telegram API rejected message delivery.'), $token),
            ], 400);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Network error dispatching Telegram alert: ' . self::sanitizeTelegramSecrets($e->getMessage(), $token),
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
     * Core static dispatcher called from Meeting lifecycle events to queue Telegram alerts asynchronously.
     */
    public static function dispatchTelegramAlert(string $type, array $variables, ?string $tenantId = null, ?string $overrideChatId = null): bool
    {
        try {
            $config = self::resolveTelegramDeliveryConfig($tenantId, $overrideChatId);
            if ($config === null) {
                return false;
            }

            SendMeetingTelegramAlertJob::dispatch($type, $variables, $tenantId, $overrideChatId)
                ->onQueue('notifications');

            return true;
        } catch (\Throwable $e) {
            $sanitizedMessage = self::sanitizeTelegramSecrets($e->getMessage());
            Log::warning('Telegram queue dispatch failed: ' . $sanitizedMessage, [
                'tenant_id' => $tenantId,
            ]);
            return false;
        }
    }

    /**
     * Execute the actual Telegram Bot API HTTP POST inside a queued worker.
     */
    public static function deliverTelegramAlertNow(string $type, array $variables, ?string $tenantId = null, ?string $overrideChatId = null): bool
    {
        try {
            $config = self::resolveTelegramDeliveryConfig($tenantId, $overrideChatId);
            if ($config === null) {
                return false;
            }

            $templateRow = DB::table('meeting_notification_templates')
                ->where('template_type', $type)
                ->where(function ($q) use ($tenantId) {
                    if ($tenantId) $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id');
                    else $q->whereNull('tenant_id');
                })->first();

            $raw = $templateRow ? $templateRow->body : (self::$factoryDefaults[$type] ?? 'Notice: {title}');

            $text = $raw;
            foreach ($variables as $k => $v) {
                $text = str_replace('{' . $k . '}', (string) $v, $text);
            }

            try {
                $res = Http::timeout(5)->post("https://api.telegram.org/bot{$config['token']}/sendMessage", [
                    'chat_id' => $config['chat_id'],
                    'text' => $text,
                    'parse_mode' => 'Markdown',
                ]);
            } catch (\Illuminate\Http\Client\ConnectionException | \Illuminate\Http\Client\RequestException $e) {
                $sanitized = self::sanitizeTelegramSecrets($e->getMessage(), $config['token']);
                Log::warning('Telegram delivery network failure: ' . $sanitized, [
                    'tenant_id' => $tenantId,
                ]);
                // Never pass raw HTTP client exception ($e) as previous, as it contains the bot token in the request URL
                throw new TelegramRetryableException("Telegram network error or timeout: {$sanitized}", 10, 0);
            }

            $status = $res->status();
            $json = $res->json();
            $ok = is_array($json) ? ($json['ok'] ?? false) : false;

            if ($res->successful() && $ok === true) {
                return true;
            }

            // Failure handling: extract sanitized error details
            $description = is_array($json) ? ($json['description'] ?? 'Telegram API error') : 'Telegram API error';
            $sanitizedDesc = self::sanitizeTelegramSecrets((string) $description, $config['token']);

            // HTTP 429: Rate Limit with Telegram retry_after parameter
            if ($status === 429) {
                $retryAfter = max(1, (int) ($res->json('parameters.retry_after') ?: $res->header('Retry-After') ?: 10));
                Log::warning("Telegram rate limited (HTTP 429). Retry after {$retryAfter}s.", [
                    'tenant_id' => $tenantId,
                    'retry_after' => $retryAfter,
                ]);
                throw new TelegramRetryableException("Telegram rate limit: {$sanitizedDesc}", $retryAfter, 429);
            }

            // HTTP 5xx (or 408 Request Timeout): Transient Server Error
            if (($status >= 500 && $status <= 599) || $status === 408) {
                Log::warning("Telegram server error (HTTP {$status}).", [
                    'tenant_id' => $tenantId,
                    'status' => $status,
                ]);
                throw new TelegramRetryableException("Telegram server error {$status}: {$sanitizedDesc}", 10, $status);
            }

            // HTTP 4xx (Permanent failures: 400 Bad Request, 401 Unauthorized, 403 Forbidden, 404 Not Found)
            Log::error("Telegram permanent delivery failure (HTTP {$status}).", [
                'tenant_id' => $tenantId,
                'status' => $status,
                'description' => $sanitizedDesc,
            ]);
            throw new TelegramPermanentException("Telegram permanent error {$status}: {$sanitizedDesc}", $status);
        } catch (CredentialEncryptionException $e) {
            // Decryption failure: stop delivery safely without leaking credentials
            Log::error('Telegram delivery halted: provider credential decryption failed.', [
                'tenant_id' => $tenantId,
                'exception' => $e::class,
            ]);
            throw $e;
        }
    }

    /**
     * Resolve enabled Telegram delivery credentials and target chat ID for a tenant.
     * Enforces strict tenant isolation and uses encrypted NotificationProvider store.
     *
     * @return array{token: string, chat_id: string}|null
     */
    protected static function resolveTelegramDeliveryConfig(?string $tenantId = null, ?string $overrideChatId = null): ?array
    {
        $scopedTenantId = ($tenantId !== null && $tenantId !== '') ? (int) $tenantId : null;

        // 1. Resolve from encrypted NotificationProvider store (strictly scoped to tenant)
        $provider = NotificationProvider::where('channel', 'telegram')
            ->where(function ($q) use ($scopedTenantId) {
                if ($scopedTenantId !== null) {
                    $q->where('tenant_id', $scopedTenantId);
                } else {
                    $q->whereNull('tenant_id');
                }
            })
            ->latest('id')
            ->first();

        if ($provider) {
            if (!$provider->is_enabled) {
                return null;
            }

            // Fail-closed credential decryption
            $creds = $provider->getDecryptedCredentials();
            $token = $creds['bot_token'] ?? ($creds['token'] ?? null);
            $chatId = $overrideChatId ?: ($creds['chat_id'] ?? ($provider->settings['default_chat_id'] ?? null));

            if (empty($token) || empty($chatId)) {
                return null;
            }

            return [
                'token' => (string) $token,
                'chat_id' => (string) $chatId,
            ];
        }

        // 2. Migration fallback for legacy records (strictly tenant-scoped)
        $enabled = DB::table('meeting_system_settings')
            ->where('setting_key', 'notification.telegram_enabled')
            ->where(function ($q) use ($scopedTenantId) {
                if ($scopedTenantId !== null) {
                    $q->where('tenant_id', $scopedTenantId);
                } else {
                    $q->whereNull('tenant_id');
                }
            })->value('setting_value');

        $legacyConfig = $scopedTenantId !== null
            ? DB::table('meeting_telegram_configs')->where('tenant_id', $scopedTenantId)->first()
            : null;

        $isEnabled = $enabled !== null
            ? ($enabled === 'true' || $enabled === '1')
            : (bool) ($legacyConfig?->is_active ?? false);

        if (!$isEnabled) {
            return null;
        }

        $token = DB::table('meeting_system_settings')
            ->where('setting_key', 'notification.telegram_bot_token')
            ->where(function ($q) use ($scopedTenantId) {
                if ($scopedTenantId !== null) {
                    $q->where('tenant_id', $scopedTenantId);
                } else {
                    $q->whereNull('tenant_id');
                }
            })->value('setting_value');

        if (empty($token) && $legacyConfig && !empty($legacyConfig->bot_token)) {
            $token = $legacyConfig->bot_token;
        }

        if (empty($token)) {
            return null;
        }

        $chatId = $overrideChatId ?: DB::table('meeting_system_settings')
            ->where('setting_key', 'notification.telegram_default_chat_id')
            ->where(function ($q) use ($scopedTenantId) {
                if ($scopedTenantId !== null) {
                    $q->where('tenant_id', $scopedTenantId);
                } else {
                    $q->whereNull('tenant_id');
                }
            })->value('setting_value');

        if (empty($chatId) && $legacyConfig && !empty($legacyConfig->chat_id)) {
            $chatId = $legacyConfig->chat_id;
        }

        if (empty($chatId)) {
            return null;
        }

        // Migrate on-the-fly to encrypted store and wipe plaintext copies
        try {
            $newProvider = new NotificationProvider();
            $newProvider->uuid = (string) Str::uuid();
            $newProvider->tenant_id = $scopedTenantId;
            $newProvider->name = 'Telegram Meeting Alerts';
            $newProvider->channel = 'telegram';
            $newProvider->provider = 'telegram';
            $newProvider->environment = 'all';
            $newProvider->is_enabled = true;
            $newProvider->credentials = [
                'bot_token' => (string) $token,
                'chat_id' => (string) $chatId,
            ];
            $newProvider->settings = [
                'default_chat_id' => (string) $chatId,
            ];
            $newProvider->save();

            DB::table('meeting_system_settings')
                ->where(function ($q) use ($scopedTenantId) {
                    if ($scopedTenantId !== null) {
                        $q->where('tenant_id', $scopedTenantId);
                    } else {
                        $q->whereNull('tenant_id');
                    }
                })
                ->where('setting_key', 'notification.telegram_bot_token')
                ->delete();

            if ($scopedTenantId !== null) {
                DB::table('meeting_telegram_configs')
                    ->where('tenant_id', $scopedTenantId)
                    ->update(['bot_token' => '']);
            }
        } catch (\Throwable) {}

        return [
            'token' => (string) $token,
            'chat_id' => (string) $chatId,
        ];
    }

    protected function getTelegramSettings(?string $tenantId): array
    {
        $scopedTenantId = ($tenantId !== null && $tenantId !== '') ? (int) $tenantId : null;

        // 1. Resolve from encrypted NotificationProvider store (strictly scoped to tenant)
        $provider = NotificationProvider::where('channel', 'telegram')
            ->where(function ($q) use ($scopedTenantId) {
                if ($scopedTenantId !== null) {
                    $q->where('tenant_id', $scopedTenantId);
                } else {
                    $q->whereNull('tenant_id');
                }
            })
            ->latest('id')
            ->first();

        $token = '';
        $botEnabled = false;
        $botUsername = '';
        $defaultChatId = '';
        $reminderMinutes = 15;

        if ($provider) {
            $botEnabled = (bool) $provider->is_enabled;
            try {
                $creds = $provider->getDecryptedCredentials();
                $token = $creds['bot_token'] ?? ($creds['token'] ?? '');
                $defaultChatId = $creds['chat_id'] ?? '';
            } catch (\Throwable) {}

            $settings = is_array($provider->settings) ? $provider->settings : [];
            $botUsername = $settings['bot_username'] ?? '';
            $defaultChatId = $defaultChatId ?: ($settings['default_chat_id'] ?? '');
            $reminderMinutes = (int) ($settings['default_reminder_minutes'] ?? 15);
        }

        $keys = [
            'notification.telegram_enabled',
            'notification.telegram_bot_token',
            'notification.telegram_bot_username',
            'notification.telegram_default_chat_id',
            'notification.default_lead_minutes',
        ];

        $rows = DB::table('meeting_system_settings')
            ->whereIn('setting_key', $keys)
            ->where(function ($q) use ($scopedTenantId) {
                if ($scopedTenantId !== null) {
                    $q->where('tenant_id', $scopedTenantId);
                } else {
                    $q->whereNull('tenant_id');
                }
            })
            ->get();

        $map = [];
        foreach ($rows as $r) {
            $map[$r->setting_key] = $r->setting_value;
        }

        if (!$provider) {
            if (empty($token) && !empty($map['notification.telegram_bot_token'])) {
                $token = $map['notification.telegram_bot_token'];
            }

            if (empty($token) && $scopedTenantId !== null) {
                $cfg = DB::table('meeting_telegram_configs')->where('tenant_id', $scopedTenantId)->first();
                if ($cfg && !empty($cfg->bot_token)) {
                    $token = $cfg->bot_token;
                    $defaultChatId = $cfg->chat_id;
                    $botEnabled = (bool) $cfg->is_active;
                }
            }

            if (isset($map['notification.telegram_enabled'])) {
                $botEnabled = $map['notification.telegram_enabled'] === 'true' || $map['notification.telegram_enabled'] === '1';
            }
        }

        if (empty($botUsername) && !empty($map['notification.telegram_bot_username'])) {
            $botUsername = $map['notification.telegram_bot_username'];
        }
        if (empty($defaultChatId) && !empty($map['notification.telegram_default_chat_id'])) {
            $defaultChatId = $map['notification.telegram_default_chat_id'];
        }
        if ($reminderMinutes === 15 && !empty($map['notification.default_lead_minutes'])) {
            $reminderMinutes = (int) $map['notification.default_lead_minutes'];
        }

        return [
            'bot_enabled' => $botEnabled,
            'bot_token' => $token,
            'bot_username' => $botUsername,
            'default_chat_id' => $defaultChatId,
            'default_reminder_minutes' => $reminderMinutes,
        ];
    }

    protected function getSettingValue(string $key, ?string $tenantId): ?string
    {
        $scopedTenantId = ($tenantId !== null && $tenantId !== '') ? (int) $tenantId : null;

        return DB::table('meeting_system_settings')
            ->where('setting_key', $key)
            ->where(function ($q) use ($scopedTenantId) {
                if ($scopedTenantId !== null) {
                    $q->where('tenant_id', $scopedTenantId);
                } else {
                    $q->whereNull('tenant_id');
                }
            })
            ->value('setting_value');
    }

    /**
     * Sanitize Telegram bot tokens out of logs and exception messages.
     */
    public static function sanitizeTelegramSecrets(?string $message, ?string $rawToken = null): string
    {
        $sanitized = preg_replace('/\/bot[^\/\s]+/', '/bot[REDACTED]', (string) $message) ?? 'Telegram error';
        if (!empty($rawToken)) {
            $sanitized = str_replace($rawToken, '[REDACTED]', $sanitized);
        }

        return $sanitized !== '' ? $sanitized : 'Telegram error';
    }
}
