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
        Schema::create('meeting_rooms', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->nullable()->unique();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->char('outlet_uuid', 36)->nullable();
            $table->foreignId('outlet_id')->nullable()->constrained('outlets')->nullOnDelete();
            $table->string('name', 150);
            $table->string('code', 50);
            $table->unsignedInteger('capacity');
            $table->string('location', 255)->nullable();
            $table->string('floor', 50)->nullable();
            $table->decimal('hourly_rate', 14, 2)->default(0.00);
            $table->char('currency', 3)->default('USD');
            $table->string('status', 30)->default('active');
            $table->boolean('is_active')->default(true);
            $table->text('amenities')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('meeting_rooms');
    }
};
