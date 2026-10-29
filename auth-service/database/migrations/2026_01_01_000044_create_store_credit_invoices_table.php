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
        Schema::create('store_credit_invoices', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->nullable()->unique();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('invoice_number', 50)->unique();
            $table->decimal('amount', 14, 2)->default(0.00);
            $table->decimal('balance', 14, 2)->default(0.00);
            $table->decimal('amount_paid', 14, 2)->default(0.00);
            $table->decimal('credit_amount', 14, 2)->default(0.00);
            $table->decimal('bonus_credit', 14, 2)->default(0.00);
            $table->string('payment_method', 30)->default('cash');
            $table->string('payment_reference', 255)->nullable();
            $table->string('status', 30)->default('paid');
            $table->unsignedBigInteger('cashier_id')->nullable();
            $table->unsignedBigInteger('outlet_id')->nullable();
            $table->date('due_date')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('store_credit_invoices');
    }
};
