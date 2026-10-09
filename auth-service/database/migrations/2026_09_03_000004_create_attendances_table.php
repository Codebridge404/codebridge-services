<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('attendances')) {
            Schema::create('attendances', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->unsignedBigInteger('employee_id')->nullable()->index();
                $table->string('outlet_id')->nullable()->index();
                $table->dateTime('clock_in');
                $table->dateTime('clock_out')->nullable();
                $table->string('status')->default('present'); // present, late, completed
                $table->string('notes')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};
