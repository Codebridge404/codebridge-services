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
        Schema::create('api_clients', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique('api_clients_uuid_unique');
            $table->string('name', 100);
            $table->string('client_key', 64)->unique('api_clients_client_key_unique');
            $table->string('client_type', 30)->default('public');
            $table->boolean('is_active')->default(true)->index('api_clients_is_active_index');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('api_clients');
    }
};
