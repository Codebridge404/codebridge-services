<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\V1\MeetingController;
use Illuminate\Console\Command;

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
