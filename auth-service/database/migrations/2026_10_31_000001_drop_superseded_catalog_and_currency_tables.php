<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Drop superseded tables in auth-service:
     * - `services_catalog` (superseded by `platform_modules` and `tenant_modules`)
     * - `currency_exchange_rates` (superseded by `exchange_rates` in operations-service)
     */
    public function up(): void
    {
        Schema::dropIfExists('services_catalog');
        Schema::dropIfExists('currency_exchange_rates');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('services_catalog')) {
            Schema::create('services_catalog', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('currency_exchange_rates')) {
            Schema::create('currency_exchange_rates', function (Blueprint $table) {
                $table->id();
                $table->string('from_currency', 3);
                $table->string('to_currency', 3);
                $table->decimal('rate', 14, 6);
                $table->timestamps();
            });
        }
    }
};

