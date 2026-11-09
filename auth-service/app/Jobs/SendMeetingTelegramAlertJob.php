<?php

namespace App\Jobs;

use App\Http\Controllers\Api\V1\MeetingTelegramController;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

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
        MeetingTelegramController::deliverTelegramAlertNow(
            $this->type,
            $this->variables,
            $this->tenantId,
            $this->overrideChatId
        );
    }
}

