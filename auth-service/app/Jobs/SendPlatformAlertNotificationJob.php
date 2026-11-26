<?php

namespace App\Jobs;

use App\Services\IpThreatDefenseService;
use App\Support\LogRedactor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Durable Queued Platform Alert Notification Job.
 *
 * Dispatches operational and security alerts (e.g., module global kill-switch
 * changes or repeated unauthorized module access thresholds) to the configured
 * NotificationProvider without blocking or rolling back administrative actions.
 */
class SendPlatformAlertNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [10, 30, 60];

    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public string $eventType,
        public string $title,
        public string $summary,
        public ?int $tenantId = null,
        public array $context = []
    ) {}

    public function handle(): void
    {
        $token = null;

        try {
            $provider = IpThreatDefenseService::resolveSecurityAlertProvider($this->tenantId);
            if (! $provider) {
                Log::info('SendPlatformAlertNotificationJob: no enabled Telegram notification provider configured.', [
                    'event_type' => $this->eventType,
                    'tenant_id' => $this->tenantId,
                ]);

                return;
            }

            $creds = $provider->getDecryptedCredentials();
            $token = $creds['bot_token'] ?? ($creds['token'] ?? null);
            $chatId = $creds['chat_id'] ?? ($provider->settings['default_chat_id'] ?? null);

            if (empty($token) || empty($chatId)) {
                Log::warning('SendPlatformAlertNotificationJob: Telegram provider missing bot token or chat_id.', [
                    'event_type' => $this->eventType,
                    'provider_id' => $provider->id,
                ]);

                return;
            }

            $safeTitle = htmlspecialchars($this->title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $safeSummary = htmlspecialchars($this->summary, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $safeEvent = htmlspecialchars($this->eventType, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $timestamp = now()->toDateTimeString();

            $text = "🔔 <b>{$safeTitle}</b>\n\n"
                ."• <b>Event:</b> <code>{$safeEvent}</code>\n"
                ."• <b>Details:</b> {$safeSummary}\n"
                ."• <b>Timestamp:</b> {$timestamp}";

            $response = Http::timeout(5)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'HTML',
            ]);

            if (! $response->successful()) {
                Log::warning('SendPlatformAlertNotificationJob: Telegram API returned HTTP '.$response->status(), [
                    'event_type' => $this->eventType,
                    'body' => LogRedactor::sanitizeTelegramSecrets((string) $response->body(), is_string($token) ? $token : null),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('SendPlatformAlertNotificationJob failed: '.LogRedactor::sanitizeTelegramSecrets($e->getMessage(), is_string($token) ? $token : null), [
                'event_type' => $this->eventType,
            ]);
        }
    }
}
