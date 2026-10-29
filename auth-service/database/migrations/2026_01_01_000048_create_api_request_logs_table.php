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
        Schema::create('api_request_logs', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->nullable()->unique();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('request_id', 64)->index();
            $table->string('cf_ray', 100)->nullable();
            $table->char('trace_id', 32)->nullable()->index();
            $table->char('span_id', 16)->nullable();
            $table->string('service_name', 50);
            $table->string('environment', 20);
            $table->string('instance_name', 255)->nullable();
            $table->string('method', 10);
            $table->text('path');
            $table->string('route_name', 255)->nullable();
            $table->json('route_params')->nullable();
            $table->json('query_params')->nullable();
            $table->json('request_data')->nullable();
            $table->unsignedSmallInteger('status_code');
            $table->string('error_code', 100)->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedInteger('duration_ms');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('api_request_logs');
    }
};
