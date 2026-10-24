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
        if (!Schema::hasTable('shift_variance_alerts')) {
            Schema::create('shift_variance_alerts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('shift_id')->index();
                $table->unsignedBigInteger('user_id')->index();
                $table->unsignedBigInteger('outlet_id')->nullable()->index();
                $table->decimal('expected_cash', 12, 2);
                $table->decimal('counted_cash', 12, 2);
                $table->decimal('cash_variance', 12, 2);
                $table->decimal('tolerance_threshold', 12, 2)->default(5.00);
                $table->string('variance_type', 20); // shortage, overage
                $table->text('closing_note')->nullable();
                $table->boolean('telegram_dispatched')->default(false);
                $table->boolean('email_dispatched')->default(false);
                $table->dateTime('dispatched_at')->nullable();
                $table->json('raw_metadata')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shift_variance_alerts');
    }
};
