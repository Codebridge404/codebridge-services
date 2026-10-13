<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\V1\MeetingController;
use Illuminate\Console\Command;

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
