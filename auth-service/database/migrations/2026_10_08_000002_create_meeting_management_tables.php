<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations for Meeting Management Systems (MMS).
     */
    public function up(): void
    {
        // 1. Meeting Rooms table
        if (!Schema::hasTable('meeting_rooms')) {
            Schema::create('meeting_rooms', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->unsignedBigInteger('outlet_id')->nullable()->index();
                $table->string('name', 150);
                $table->string('code', 50)->nullable();
                $table->string('floor', 50)->nullable();
                $table->integer('capacity')->default(6);
                $table->text('amenities')->nullable(); // comma-separated or json
                $table->string('status', 30)->default('active'); // active, maintenance, inactive
                $table->timestamps();
            });
        }

        // 2. Meetings table
        if (!Schema::hasTable('meetings')) {
            Schema::create('meetings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->unsignedBigInteger('room_id')->index();
                $table->unsignedBigInteger('organizer_id')->index();
                $table->string('title', 255);
                $table->text('description')->nullable();
                $table->dateTime('start_time')->index();
                $table->dateTime('end_time')->index();
                $table->string('status', 30)->default('scheduled'); // scheduled, in_progress, completed, cancelled
                $table->string('meeting_type', 50)->default('internal'); // internal, client, review, training
                $table->string('meeting_link', 255)->nullable();
                $table->timestamps();
            });
        }

        // 3. Meeting Attendees table
        if (!Schema::hasTable('meeting_attendees')) {
            Schema::create('meeting_attendees', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('meeting_id')->index();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('name', 150);
                $table->string('email', 150)->nullable();
                $table->string('role', 50)->default('attendee'); // organizer, attendee, optional
                $table->string('rsvp_status', 30)->default('pending'); // accepted, declined, tentative, pending
                $table->timestamps();
            });
        }

        // 4. Meeting Minutes & Action Items table
        if (!Schema::hasTable('meeting_minutes')) {
            Schema::create('meeting_minutes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('meeting_id')->unique();
                $table->unsignedBigInteger('recorded_by')->nullable()->index();
                $table->longText('content')->nullable();
                $table->json('action_items')->nullable();
                $table->string('status', 30)->default('draft'); // draft, published
                $table->timestamps();
            });
        }

        // 5. Telegram Integration Settings table
        if (!Schema::hasTable('meeting_telegram_configs')) {
            Schema::create('meeting_telegram_configs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->unique();
                $table->string('bot_token', 255)->nullable();
                $table->string('chat_id', 100)->nullable();
                $table->boolean('is_enabled')->default(false);
                $table->boolean('notify_on_booking')->default(true);
                $table->boolean('notify_15m_before')->default(true);
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('meeting_telegram_configs');
        Schema::dropIfExists('meeting_minutes');
        Schema::dropIfExists('meeting_attendees');
        Schema::dropIfExists('meetings');
        Schema::dropIfExists('meeting_rooms');
    }
};
