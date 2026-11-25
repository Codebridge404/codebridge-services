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
        if (! Schema::hasTable('platform_maintenance_settings')) {
            Schema::create('platform_maintenance_settings', function (Blueprint $table) {
                $table->id();
                $table->string('key', 50)->unique('platform_maintenance_key_unique');
                $table->boolean('enabled')->default(false);
                $table->string('message', 500)->nullable();
                $table->dateTime('starts_at', 6)->nullable();
                $table->dateTime('ends_at', 6)->nullable();
                $table->boolean('block_mutations')->default(false);
                $table->foreignId('updated_by')
                    ->nullable()
                    ->constrained('users', 'id', 'platform_maintenance_updated_by_foreign')
                    ->nullOnDelete();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('platform_maintenance_settings');
    }
};
