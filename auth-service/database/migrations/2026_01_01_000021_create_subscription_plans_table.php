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
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->string('name', 150);
            $table->string('slug', 100)->unique();
            $table->decimal('price', 14, 2)->default(0.00);
            $table->char('currency', 3)->default('USD');
            $table->string('billing_cycle', 20)->default('monthly');
            $table->json('features_json')->nullable();
            $table->unsignedInteger('max_outlets')->default(5);
            $table->unsignedInteger('max_registers')->default(10);
            $table->unsignedInteger('max_users')->default(25);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_plans');
    }
};
