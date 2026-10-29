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
        Schema::create('meeting_catering_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_id')->constrained('meetings')->cascadeOnDelete();
            $table->string('package_name', 150);
            $table->unsignedInteger('quantity');
            $table->decimal('unit_cost', 14, 2);
            $table->decimal('total_cost', 14, 2);
            $table->char('currency', 3)->default('USD');
            $table->dateTime('delivery_time')->nullable();
            $table->string('status', 30)->default('ordered');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('meeting_catering_orders');
    }
};
