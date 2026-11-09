<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Queued Telegram dispatcher for POS shift variance alerts.
 * Resolves the Telegram bot token inside handle() so secrets are not stored in queue payloads.
 */
class SendShiftVarianceTelegramJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [10, 60];

    public int $timeout = 15;

    public function __construct(
        public string $chatId,
        public string $message,
        public ?int $shiftId = null
    ) {
        $this->onQueue('notifications');
    }

    public function handle(): void
    {
        $botToken = (string) config('services.telegram.bot_token');
        if ($botToken === '' || $this->chatId === '') {
            Log::info('Telegram shift variance job skipped: missing bot token or chat ID.', [
                'shift_id' => $this->shiftId,
            ]);
            return;
        }

        try {
            $response = Http::timeout(6)->post("https://api.telegram.org/bot{$botToken}/sendMessage", [
                'chat_id' => $this->chatId,
                'text' => $this->message,
                'parse_mode' => 'HTML',
            ]);

            if ($response->successful()) {
                return;
            }

            Log::error('Telegram API returned non-success status for shift variance alert.', [
                'status' => $response->status(),
                'shift_id' => $this->shiftId,
            ]);

            if ($response->serverError() || $response->status() === 429) {
                throw new \RuntimeException("Telegram API transient HTTP {$response->status()}");
            }
        } catch (\Throwable $e) {
            $sanitized = preg_replace('/\/bot[^\/\s]+/', '/bot[REDACTED]', $e->getMessage()) ?? 'Unknown Telegram error';
            Log::error('Failed to dispatch Telegram shift variance alert.', [
                'shift_id' => $this->shiftId,
                'error' => $sanitized,
            ]);
            throw new \RuntimeException($sanitized, (int) $e->getCode());
        }
    }
}

