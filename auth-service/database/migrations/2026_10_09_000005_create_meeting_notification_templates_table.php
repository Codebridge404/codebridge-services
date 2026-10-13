<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('meeting_notification_templates')) {
            Schema::create('meeting_notification_templates', function (Blueprint $table) {
                $table->id();
                $table->string('tenant_id')->nullable()->index();
                $table->string('type')->index(); // confirmation, reminder, change, cancellation
                $table->string('name');
                $table->text('content');
                $table->boolean('is_customized')->default(false);
                $table->timestamps();
            });

            // Seed initial factory templates matching Spring Boot TemplateRenderService
            $defaults = [
                [
                    'type' => 'confirmation',
                    'name' => 'Meeting Creation & Confirmation',
                    'content' => "📅 *Meeting Confirmed*\n*Title:* {title}\n*Room:* {room}\n*Time:* {startTime} - {endTime}\n*Organizer:* {organizer}\n*Equipment:* {materials}",
                    'is_customized' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'type' => 'reminder',
                    'name' => 'Pre-Meeting Countdown Reminder',
                    'content' => "⏰ *Upcoming Meeting Reminder*\n*Title:* {title}\n*Room:* {room}\n*Starts In:* {leadMinutes} minutes ({startTime})\n*Organizer:* {organizer}\nPlease arrive on time or check in at the room display kiosk.",
                    'is_customized' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'type' => 'change',
                    'name' => 'Meeting Approval & Modification',
                    'content' => "🔄 *Meeting Updated / Approved*\n*Title:* {title}\n*Room:* {room}\n*Schedule:* {startTime} - {endTime}\n*Status:* Approved & Scheduled\n*Organizer:* {organizer}",
                    'is_customized' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'type' => 'cancellation',
                    'name' => 'Meeting Cancellation & Turnover',
                    'content' => "❌ *Meeting Cancelled*\n*Title:* {title}\n*Room:* {room}\n*Scheduled Time:* {startTime}\n*Reason:* {reason}\nAllocated equipment and staff have been liberated.",
                    'is_customized' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ];

            DB::table('meeting_notification_templates')->insert($defaults);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_notification_templates');
    }
};
