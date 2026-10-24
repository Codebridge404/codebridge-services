<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\V1\MeetingController;
use Illuminate\Console\Command;

/**
 * [BD-0081] Scheduled Cron Meeting Reminder Dispatcher Command
 *
 * @ticket BD-0081
 * @component auth-service
 * @file codebridge-services/auth-service/app/Console/Commands/SendMeetingRemindersCommand.php
 * @related-model DB table meetings, DB table meeting_notifications
 * @entry-point SendMeetingRemindersCommand::handle()
 * @routes
 *  - Command: php artisan meetings:send-reminders
 * @date-time 2026-10-23T15:40:00+07:00
 *
 * Process Capabilities:
 * Step 1 (Schedule Window Scanning): Scans meeting schedule table for meetings starting within the next 15 minutes.
 * Step 2 (Deduplication Check): Ensures reminders are only sent once per recipient per meeting event.
 * Step 3 (Multi-Channel Dispatch): Sends push alerts via Telegram bot, email, and WebSockets.
 * Step 4 (Failure Handling & Retry): Logs delivery failures and attempts secondary notification channel fallback.
 * Step 5 (Execution Summary): Outputs summary table of sent notifications and affected participants to console.
 */
class SendMeetingRemindersCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'meetings:send-reminders';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Dispatch advance countdown reminders to meeting organizers and attendees';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Scanning upcoming meetings for advance countdown reminders...');
        $dispatchedCount = MeetingController::dispatchAdvanceReminders();
        $this->info("Completed: {$dispatchedCount} advance reminder(s) dispatched.");
        return Command::SUCCESS;
    }
}
