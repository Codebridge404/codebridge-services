<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('purchases')) {
            Schema::create('purchases', function (Blueprint $table) {
                $table->id();
                $table->string('purchase_ref')->unique();
                $table->string('supplier');
                $table->string('invoice_no');
                $table->decimal('total_amount', 12, 2);
                $table->string('status')->default('received');
                $table->date('received_date')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('purchase_orders')) {
            Schema::create('purchase_orders', function (Blueprint $table) {
                $table->id();
                $table->string('po_number')->unique();
                $table->string('supplier');
                $table->integer('items_count')->default(1);
                $table->decimal('est_total', 12, 2);
                $table->string('status')->default('pending'); // pending, approved, received, cancelled
                $table->date('order_date')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('purchases');
    }
};
