<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('coupons')) {
            Schema::create('coupons', function (Blueprint $table) {
                $table->id();
                $table->string('code')->unique();
                $table->string('discount_type')->default('percentage'); // percentage, fixed
                $table->decimal('discount_value', 12, 2);
                $table->decimal('min_spend', 12, 2)->default(0);
                $table->boolean('is_active')->default(true);
                $table->dateTime('expires_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('coupons');
    }
};
