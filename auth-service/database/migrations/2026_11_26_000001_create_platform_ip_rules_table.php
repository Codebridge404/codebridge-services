<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('platform_ip_rules')) {
            Schema::create('platform_ip_rules', function (Blueprint $table) {
                $table->id();
                $table->char('uuid', 36)->unique('platform_ip_rules_uuid_unique');
                $table->string('ip_or_cidr', 64)->unique('platform_ip_rules_cidr_unique');
                $table->string('rule_type', 20); // 'allow' | 'block'
                $table->string('reason', 255)->nullable();
                $table->boolean('is_active')->default(true);
                $table->dateTime('expires_at', 6)->nullable();
                $table->foreignId('created_by')
                    ->nullable()
                    ->constrained('users', 'id', 'platform_ip_rules_created_by_foreign')
                    ->nullOnDelete();
                $table->timestamps();

                $table->index(['rule_type', 'is_active', 'expires_at'], 'platform_ip_rules_lookup_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_ip_rules');
    }
};
