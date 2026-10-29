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
        Schema::create('deals', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->nullable()->unique();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('title', 150);
            $table->string('company', 150)->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->decimal('value', 14, 2)->default(0.00);
            $table->decimal('deal_value', 14, 2)->default(0.00);
            $table->string('stage', 50)->default('new');
            $table->integer('probability')->default(50);
            $table->string('owner_name', 150)->nullable()->default('Sales Team');
            $table->date('expected_close_date')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('deals');
    }
};
