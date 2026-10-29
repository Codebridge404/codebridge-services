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
        Schema::create('payment_provider_accounts', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->string('name', 100);
            $table->string('provider', 50);
            $table->string('merchant_id', 100);
            $table->string('environment', 20)->default('sandbox');
            $table->string('api_base_url', 255);
            $table->text('api_key_encrypted');
            $table->text('rsa_public_key')->nullable();
            $table->text('rsa_private_key_encrypted')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_provider_accounts');
    }
};
