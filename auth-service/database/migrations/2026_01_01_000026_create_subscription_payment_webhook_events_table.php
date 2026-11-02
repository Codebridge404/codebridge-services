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
        Schema::create('subscription_payment_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_account_id')->constrained('payment_provider_accounts')->cascadeOnDelete();
            $table->string('event_id', 100);
            $table->string('event_type', 100);
            $table->json('payload');
            $table->text('signature')->nullable();
            $table->string('status', 30)->default('pending');
            $table->timestamp('processed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_payment_webhook_events');
    }
};
