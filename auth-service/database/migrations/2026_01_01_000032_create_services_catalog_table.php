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
        Schema::create('services_catalog', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('domain', 255)->nullable();
            $table->string('category', 100);
            $table->text('logo_url')->nullable();
            $table->text('cancellation_url')->nullable();
            $table->decimal('typical_price', 14, 2)->nullable();
            $table->char('currency', 3)->nullable();
            $table->boolean('is_verified')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('services_catalog');
    }
};
