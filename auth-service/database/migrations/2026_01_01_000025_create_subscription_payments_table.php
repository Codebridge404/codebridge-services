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
        Schema::create('subscription_payments', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->foreignId('tenant_subscription_id')->constrained('tenant_subscriptions')->cascadeOnDelete();
            $table->foreignId('provider_account_id')->constrained('payment_provider_accounts')->cascadeOnDelete();
            $table->string('merchant_reference', 100);
            $table->string('tran_id', 100)->nullable();
            $table->string('bank_ref', 100)->nullable();
            $table->decimal('expected_amount', 14, 2);
            $table->char('expected_currency', 3);
            $table->decimal('paid_amount', 14, 2)->nullable();
            $table->char('paid_currency', 3)->nullable();
            $table->string('status', 30)->default('pending');
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_payments');
    }
};
