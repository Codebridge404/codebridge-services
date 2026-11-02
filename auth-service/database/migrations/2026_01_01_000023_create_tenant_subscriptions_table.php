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
        Schema::create('tenant_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->nullable()->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained('subscription_plans')->nullOnDelete();
            $table->string('plan_name', 150)->nullable();
            $table->string('status', 30)->default('active');
            $table->decimal('agreed_price', 14, 2)->default(0.00);
            $table->decimal('price', 14, 2)->nullable();
            $table->char('currency', 3)->default('USD');
            $table->string('billing_cycle', 20)->default('monthly');
            $table->json('entitlements_json')->nullable();
            $table->unsignedInteger('max_outlets')->default(5);
            $table->unsignedInteger('max_registers')->default(10);
            $table->unsignedInteger('max_users')->default(25);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_subscriptions');
    }
};
