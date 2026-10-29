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
        Schema::create('platform_modules', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->string('name', 128);
            $table->string('monogram', 8)->nullable();
            $table->string('category', 64);
            $table->text('description');
            $table->string('icon', 64);
            $table->text('logo_url')->nullable();
            $table->string('href', 255);
            $table->string('bg_pill', 128)->nullable();
            $table->string('card_bg', 255)->nullable();
            $table->boolean('is_core')->default(false);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('platform_modules');
    }
};
