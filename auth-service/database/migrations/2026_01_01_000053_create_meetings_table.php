<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('meetings', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->nullable()->unique();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('room_id')->constrained('meeting_rooms')->cascadeOnDelete();
            $table->foreignId('organizer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('series_id')->nullable()->constrained('meeting_series')->nullOnDelete();
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->string('meeting_type', 50)->nullable();
            $table->dateTime('start_time');
            $table->dateTime('end_time');
            $table->string('status', 30)->default('scheduled');
            $table->text('virtual_link')->nullable();
            $table->text('meeting_link')->nullable();
            $table->timestamp('checked_in_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('meetings');
    }
};
