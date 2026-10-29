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
        Schema::create('digital_vouchers', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->nullable()->unique();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('voucher_code', 64)->nullable()->unique();
            $table->string('code', 50)->nullable()->unique();
            $table->string('title', 255)->default('');
            $table->decimal('value', 14, 2)->default(0.00);
            $table->char('currency', 3)->default('USD');
            $table->decimal('discount_amount', 14, 2)->default(0.00);
            $table->string('discount_type', 20)->default('fixed');
            $table->boolean('is_used')->default(false);
            $table->unsignedBigInteger('issued_to_customer_id')->nullable();
            $table->unsignedBigInteger('redeemed_by_customer_id')->nullable();
            $table->dateTime('redeemed_at')->nullable();
            $table->string('status', 30)->default('active');
            $table->dateTime('expires_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('digital_vouchers');
    }
};
