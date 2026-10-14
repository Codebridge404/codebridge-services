<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('online_orders')) {
            Schema::create('online_orders', function (Blueprint $table) {
                $table->id();
                $table->string('order_number')->unique();
                $table->unsignedBigInteger('customer_id')->nullable();
                $table->string('customer_name');
                $table->string('customer_phone');
                $table->string('delivery_type')->default('pickup'); // pickup, delivery
                $table->text('delivery_address')->nullable();
                $table->decimal('subtotal', 12, 2);
                $table->decimal('tax_amount', 12, 2)->default(0);
                $table->decimal('delivery_fee', 12, 2)->default(0);
                $table->decimal('grand_total', 12, 2);
                $table->string('payment_status')->default('pending'); // pending, paid, refunded
                $table->string('fulfillment_status')->default('confirmed'); // draft, confirmed, preparing, ready, completed, cancelled
                $table->string('payment_method')->default('khqr');
                $table->string('reference_number')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('online_order_lines')) {
            Schema::create('online_order_lines', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('online_order_id');
                $table->unsignedBigInteger('product_id');
                $table->string('product_name');
                $table->decimal('quantity', 12, 4);
                $table->decimal('unit_price', 12, 2);
                $table->decimal('subtotal', 12, 2);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('online_order_lines');
        Schema::dropIfExists('online_orders');
    }
};
