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
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->nullable()->unique();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 150);
            $table->string('slug', 150)->unique();
            $table->string('client_tier', 30)->default('business_runner');
            $table->text('logo_url')->nullable();
            $table->string('status', 30)->default('active');

            // Organizational metadata
            $table->string('email', 255)->nullable();
            $table->string('phone', 30)->nullable();
            $table->text('address')->nullable();
            $table->string('country', 100)->nullable();
            $table->string('currency', 10)->default('USD');
            $table->string('legal_name', 150)->nullable();
            $table->string('company_code', 50)->nullable();
            $table->string('business_type', 50)->nullable();
            $table->text('receipt_header')->nullable();
            $table->text('receipt_footer')->nullable();
            $table->json('enabled_modules')->nullable();
            $table->json('payment_settings')->nullable();
            $table->unsignedInteger('max_outlets')->default(5);
            $table->unsignedInteger('max_registers')->default(10);
            $table->unsignedInteger('max_users')->default(25);
            $table->timestamp('trial_ends_at')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
