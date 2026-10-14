<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('refund_lines')) {
            Schema::create('refund_lines', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('refund_id');
                $table->unsignedBigInteger('sale_line_id');
                $table->unsignedBigInteger('product_id');
                $table->decimal('quantity', 12, 4);
                $table->decimal('unit_price', 12, 2);
                $table->decimal('refund_subtotal', 12, 2);
                $table->string('restock_decision')->default('restock'); // restock, wastage, non_returnable
                $table->string('reason')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('refund_lines');
    }
};
