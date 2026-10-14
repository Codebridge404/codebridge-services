<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('restaurant_tables')) {
            Schema::create('restaurant_tables', function (Blueprint $table) {
                $table->id();
                $table->string('name'); // Table 01, Table 02, VIP Lounge 1
                $table->string('zone')->default('Main Floor'); // Main Floor, Patio, VIP Room
                $table->integer('capacity')->default(4);
                $table->string('status')->default('vacant'); // vacant, occupied, reserved, bill_requested
                $table->unsignedBigInteger('active_sale_id')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('kds_tickets')) {
            Schema::create('kds_tickets', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('sale_id')->nullable();
                $table->string('ticket_number');
                $table->string('order_type')->default('dine_in'); // dine_in, takeaway, delivery
                $table->string('table_name')->nullable();
                $table->json('items'); // array of item names & quantities
                $table->string('status')->default('pending'); // pending, preparing, ready, bumped
                $table->integer('prep_time_minutes')->default(0);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('kds_tickets');
        Schema::dropIfExists('restaurant_tables');
    }
};
