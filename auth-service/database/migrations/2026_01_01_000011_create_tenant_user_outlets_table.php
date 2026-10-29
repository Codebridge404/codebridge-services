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
        Schema::create('tenant_user_outlets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_user_id')->constrained('tenant_users')->cascadeOnDelete();
            $table->char('outlet_uuid', 36);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_user_id', 'outlet_uuid'], 'uq1_tenant_user_outlets');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_user_outlets');
    }
};
