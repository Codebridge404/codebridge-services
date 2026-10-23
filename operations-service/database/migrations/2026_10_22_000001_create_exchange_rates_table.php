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
        if (!Schema::hasTable('exchange_rates')) {
            Schema::create('exchange_rates', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->string('base_currency', 3)->default('USD');
                $table->string('target_currency', 3)->default('KHR');
                $table->decimal('rate', 12, 4); // e.g. 4100.0000
                $table->decimal('bid_rate', 12, 4)->nullable(); // NBC buying rate
                $table->decimal('ask_rate', 12, 4)->nullable(); // NBC selling rate
                $table->string('source', 50)->default('NBC_LIVE'); // NBC_LIVE, NBC_OFFICIAL, MANUAL
                $table->date('effective_date')->index();
                $table->boolean('is_active')->default(true)->index();
                $table->json('raw_payload')->nullable();
                $table->timestamp('synced_at')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
