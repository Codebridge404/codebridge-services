<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\V1\MeetingController;
use Illuminate\Console\Command;

/**
 * [BD-0082] Scheduled Auto-Release for Unclaimed Meeting Rooms Command
 *
 * @ticket BD-0082
 * @component auth-service
 * @file codebridge-services/auth-service/app/Console/Commands/AutoReleaseMeetingsCommand.php
 * @related-model DB table meetings, DB table meeting_rooms
 * @entry-point AutoReleaseMeetingsCommand::handle()
 * @routes
 *  - Command: php artisan meetings:auto-release
 * @date-time 2026-10-23T15:40:00+07:00
 *
 * Process Capabilities:
 * Step 1 (Unclaimed Room Detection): Identifies active meeting reservations with no check-in after 15 minutes.
 * Step 2 (Reservation Cancellation): Automatically cancels the unclaimed reservation to prevent ghost bookings.
 * Step 3 (Room Status Release): Marks room as available immediately for on-demand booking by other staff.
 * Step 4 (Organizer Notification): Dispatches notification to original organizer informing them of the auto-release.
 * Step 5 (Audit Logging): Records automatic release event in system audit trail for facility management analytics.
 */
class AutoReleaseMeetingsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'meetings:auto-release';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Automatically cancel unattended confirmed meetings past the check-in grace period and restock equipment';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting automated unattended meeting release check...');
        $releasedCount = MeetingController::releaseUnattendedMeetings();
        $this->info("Completed: {$releasedCount} ghost room(s) released and restocked.");
        return Command::SUCCESS;
    }
}
