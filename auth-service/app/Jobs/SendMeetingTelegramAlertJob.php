<?php

namespace App\Jobs;

use App\Exceptions\CredentialEncryptionException;
use App\Exceptions\TelegramPermanentException;
use App\Exceptions\TelegramRetryableException;
use App\Http\Controllers\Api\V1\MeetingTelegramController;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Asynchronous dispatcher for meeting lifecycle Telegram alerts.
 * Keeps secrets out of queued payloads by resolving the bot token inside handle().
 */
class SendMeetingTelegramAlertJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [10, 60];

    public int $timeout = 15;

    /**
     * @param  array<string, mixed>  $variables
     */
    public function __construct(
        public string $type,
        public array $variables,
        public ?string $tenantId = null,
        public ?string $overrideChatId = null
    ) {
        $this->onQueue('notifications');
    }

    public function handle(): void
    {
        try {
            MeetingTelegramController::deliverTelegramAlertNow(
                $this->type,
                $this->variables,
                $this->tenantId,
                $this->overrideChatId
            );
        } catch (TelegramRetryableException $e) {
            // [P2 Fix] Respect Telegram's retry delay or configured backoff
            if ($this->attempts() < $this->tries) {
                $delay = $e->retryAfter > 0 ? $e->retryAfter : ($this->backoff[max(0, $this->attempts() - 1)] ?? 10);
                $this->release($delay);
                return;
            }

            // Exhausted attempts: ensure visible failure log when invoked directly or under faked queue, then throw sanitized exception
            if (! $this->job || $this->job instanceof \Illuminate\Queue\Jobs\FakeJob) {
                $this->failed($e);
            }
            if ($this->job) {
                $this->fail($e);
            }
            throw $e;
        } catch (TelegramPermanentException $e) {
            // Permanent failure (400, 401, 403, 404): stop retrying immediately
            if (! $this->job || $this->job instanceof \Illuminate\Queue\Jobs\FakeJob) {
                $this->failed($e);
            }
            $this->fail($e);
        } catch (CredentialEncryptionException $e) {
            // Decryption failure / corrupted ciphertext: stop delivery safely without retry
            if (! $this->job || $this->job instanceof \Illuminate\Queue\Jobs\FakeJob) {
                $this->failed($e);
            }
            $this->fail($e);
        }
    }

    /**
     * Handle job failure.
     * Guarantees no bot token is exposed in logs.
     */
    public function failed(?\Throwable $exception): void
    {
        $sanitizedMessage = $exception
            ? (preg_replace('/\/bot[^\/\s]+/', '/bot[REDACTED]', $exception->getMessage()) ?? 'Unknown error')
            : 'Unknown error';

        Log::error("SendMeetingTelegramAlertJob failed: {$sanitizedMessage}", [
            'type' => $this->type,
            'tenant_id' => $this->tenantId,
        ]);
    }
}
