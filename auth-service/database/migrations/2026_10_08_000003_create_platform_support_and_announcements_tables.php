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
        if (!Schema::hasTable('support_tickets')) {
            Schema::create('support_tickets', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->string('tenant_id')->nullable()->index();
                $table->string('tenant_name');
                $table->string('requester_name');
                $table->string('requester_email')->nullable();
                $table->string('subject');
                $table->text('description')->nullable();
                $table->enum('priority', ['urgent', 'high', 'normal'])->default('normal');
                $table->enum('status', ['open', 'in_progress', 'resolved'])->default('open');
                $table->string('category')->default('General Support');
                $table->text('response_note')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('platform_announcements')) {
            Schema::create('platform_announcements', function (Blueprint $table) {
                $table->id();
                $table->string('title');
                $table->text('message');
                $table->enum('severity', ['info', 'warning', 'critical'])->default('info');
                $table->boolean('is_active')->default(true);
                $table->string('target_audience')->default('all');
                $table->timestamp('starts_at')->nullable();
                $table->timestamp('ends_at')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('platform_announcements');
        Schema::dropIfExists('support_tickets');
    }
};
