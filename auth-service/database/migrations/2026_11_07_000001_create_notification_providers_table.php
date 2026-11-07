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
        Schema::create('notification_providers', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();

            $table->foreignId('tenant_id')->nullable()
                ->constrained('tenants')->restrictOnDelete();

            $table->string('name', 100);
            $table->string('channel', 20);
            $table->string('provider', 50);
            $table->string('environment', 20);

            $table->text('credentials');
            $table->json('settings');

            $table->boolean('is_enabled')->default(false);
            $table->unsignedInteger('config_version')->default(1);

            $table->foreignId('created_by')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->foreignId('updated_by')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->dateTime('last_tested_at')->nullable();
            $table->timestamps();

            $table->index(
                ['tenant_id', 'environment', 'channel', 'is_enabled'],
                'notification_providers_scope_idx'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_providers');
    }
};
