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
        Schema::create('customer_credit_ledgers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->decimal('amount', 14, 2)->default(0.00);
            $table->decimal('amount_change', 14, 2)->default(0.00);
            $table->decimal('debit_amount', 14, 2)->default(0.00);
            $table->decimal('credit_amount', 14, 2)->default(0.00);
            $table->decimal('balance_before', 14, 2)->default(0.00);
            $table->decimal('balance_after', 14, 2)->default(0.00);
            $table->string('entry_type', 50)->default('manual');
            $table->string('transaction_type', 50)->default('manual');
            $table->string('reference_id', 100)->nullable();
            $table->string('reference', 100)->nullable();
            $table->unsignedBigInteger('invoice_id')->nullable();
            $table->unsignedBigInteger('voucher_id')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->boolean('is_expired')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_credit_ledgers');
    }
};
